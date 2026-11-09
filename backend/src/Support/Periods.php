<?php

declare(strict_types=1);

namespace Sacco\Support;

use PDO;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;

/**
 * Closed years and the SACCO's bank and cash accounts.
 *
 * Once a year is closed its books are final: nothing can be recorded with a date in it. A
 * correction goes in the open year, usually as a journal entry.
 */
final class Periods
{
    private static ?int $lastClosed = null;

    public static function lastClosedYear(?PDO $db = null): ?int
    {
        if (self::$lastClosed === null) {
            $y = ($db ?? Database::tenant())->query('SELECT MAX(year) FROM closed_years')->fetchColumn();
            self::$lastClosed = $y ? (int) $y : 0;
        }
        return self::$lastClosed ?: null;
    }

    public static function forget(): void
    {
        self::$lastClosed = null;
    }

    public static function isClosed(string $date): bool
    {
        $last = self::lastClosedYear();
        return $last !== null && (int) substr($date, 0, 4) <= $last;
    }

    /** @throws LedgerException when the date is in a closed year */
    public static function assertOpen(string $date): void
    {
        if (self::isClosed($date)) {
            throw new LedgerException('The books for ' . substr($date, 0, 4) . ' are closed, so nothing can be dated ' . date('j M Y', strtotime($date)) . '. Record it in the open year, or ask the super admin to reopen the year.');
        }
    }

    /** For controllers: a 422 reply instead of an exception */
    public static function check(string $date): void
    {
        try {
            self::assertOpen($date);
        } catch (LedgerException $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /** The bank and cash accounts money can go through */
    public static function moneyAccounts(PDO $db): array
    {
        return $db->query("SELECT id, code, name, system_key FROM gl_accounts WHERE is_money = 1 AND status = 'active' ORDER BY system_key = 'bank' DESC, code")->fetchAll();
    }

    /** The account chosen on a form (`money_account_id`), checked; null means the main bank account */
    public static function moneyAccount(Request $request): ?int
    {
        $id = (int) $request->input('money_account_id', 0);
        if (!$id) {
            return null;
        }
        $stmt = Database::tenant()->prepare("SELECT id FROM gl_accounts WHERE id = ? AND is_money = 1 AND status = 'active'");
        $stmt->execute([$id]);
        return $stmt->fetchColumn() ? $id : Response::error('Choose a bank or cash account.', 422, ['money_account_id' => 'Invalid']);
    }
}
