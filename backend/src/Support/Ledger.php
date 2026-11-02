<?php

declare(strict_types=1);

namespace Sacco\Support;

use PDO;
use Sacco\Auth\Auth;
use Sacco\Database;

/**
 * The only way money moves. Each posting locks the member's row first, so two postings to the
 * same member at once (an admin entry during a payroll upload) queue up instead of both reading
 * the same old balance.
 */
final class Ledger
{
    public const ACCOUNTS = ['savings', 'shares'];

    /**
     * Adds a row and returns it. $cents is signed: positive adds, negative takes away.
     * Call inside Database::transaction.
     *
     * @throws LedgerException when the account would go below zero
     */
    public static function post(PDO $db, int $userId, string $account, string $type, int $cents, array $meta = []): array
    {
        if (!in_array($account, self::ACCOUNTS, true)) {
            throw new \InvalidArgumentException("Unknown account $account");
        }
        if ($cents === 0) {
            throw new LedgerException('The amount can\'t be zero.');
        }
        if (!$db->inTransaction()) {
            throw new \LogicException('Ledger::post must run inside a transaction');
        }

        $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
        $balance = self::balance($db, $userId, $account) + $cents;
        if ($balance < 0) {
            throw new LedgerException('That would take the ' . $account . ' balance below zero (it has ' . number_format(self::balance($db, $userId, $account) / 100, 2) . ').');
        }
        if ($balance > Money::MAX_CENTS) {
            throw new LedgerException('That amount is too large.');
        }

        $db->prepare(
            'INSERT INTO transactions (user_id, account, type, amount, balance_after, value_date, reference, narration, reversal_of, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $account, $type, Money::toDecimal($cents), Money::toDecimal($balance),
            $meta['value_date'] ?? date('Y-m-d'),
            $meta['reference'] ?? null,
            $meta['narration'] ?? null,
            $meta['reversal_of'] ?? null,
            $meta['created_by'] ?? Auth::id(),
        ]);
        return self::find($db, (int) $db->lastInsertId());
    }

    /** Cancels a row with an equal and opposite one. A row can be reversed once; a reversal can't be. */
    public static function reverse(PDO $db, int $transactionId, string $reason): array
    {
        $original = self::find($db, $transactionId);
        if (!$original) {
            throw new LedgerException('That transaction doesn\'t exist.');
        }
        if ($original['type'] === 'reversal') {
            throw new LedgerException('A reversal can\'t itself be reversed. Record a new entry instead.');
        }
        if ($original['reversed_by']) {
            throw new LedgerException('This transaction has already been reversed.');
        }
        return self::post($db, (int) $original['user_id'], $original['account'], 'reversal', -Money::fromDecimal($original['amount']), [
            'reversal_of' => $transactionId,
            'reference' => $original['reference'],
            'narration' => $reason,
        ]);
    }

    /** In cents */
    public static function balance(PDO $db, int $userId, string $account): int
    {
        $stmt = $db->prepare('SELECT balance_after FROM transactions WHERE user_id = ? AND account = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId, $account]);
        return Money::fromDecimal($stmt->fetchColumn() ?: null);
    }

    /** @return array{savings: string, shares: string} as decimals */
    public static function balances(int $userId): array
    {
        $db = Database::tenant();
        return [
            'savings' => Money::toDecimal(self::balance($db, $userId, 'savings')),
            'shares' => Money::toDecimal(self::balance($db, $userId, 'shares')),
        ];
    }

    /** A row with who recorded it and, if reversed, the reversal's id */
    public static function find(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare(self::SELECT . ' WHERE t.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public const SELECT = 'SELECT t.*, u.full_name AS created_by_name, r.id AS reversed_by
        FROM transactions t
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN transactions r ON r.reversal_of = t.id';

    /** A row as the frontend sees it */
    public static function present(array $t): array
    {
        return [
            'id' => (int) $t['id'],
            'account' => $t['account'],
            'type' => $t['type'],
            'amount' => $t['amount'],
            'balance_after' => $t['balance_after'],
            'value_date' => $t['value_date'],
            'reference' => $t['reference'],
            'narration' => $t['narration'],
            'reversal_of' => $t['reversal_of'] ? (int) $t['reversal_of'] : null,
            'reversed_by' => $t['reversed_by'] ? (int) $t['reversed_by'] : null,
            'created_by_name' => $t['created_by_name'],
            'created_at' => $t['created_at'],
        ];
    }

    /** A page of an account's rows, newest first */
    public static function history(int $userId, ?string $account, int $page, int $perPage = 50): array
    {
        $where = 't.user_id = ?';
        $args = [$userId];
        if ($account !== null) {
            $where .= ' AND t.account = ?';
            $args[] = $account;
        }
        $db = Database::tenant();
        $count = $db->prepare("SELECT COUNT(*) FROM transactions t WHERE $where");
        $count->execute($args);
        $stmt = $db->prepare(self::SELECT . " WHERE $where ORDER BY t.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
        $stmt->execute($args);
        return [
            'transactions' => array_map([self::class, 'present'], $stmt->fetchAll()),
            'total' => (int) $count->fetchColumn(),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
