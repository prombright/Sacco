<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Auth\Roles;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Money;

/**
 * Receipts: one for every movement on a member's account - deposits, withdrawals, transfers,
 * dividends, fees and loan repayments. The numbers come from the records (RCT-T = savings or
 * shares entry, RCT-L = loan repayment, RCT-F = membership fee in cash), so a receipt can always
 * be printed again and always says the same thing.
 */
final class ReceiptController
{
    private const TITLES = [
        'deposit' => 'Deposit', 'opening_balance' => 'Opening balance', 'withdrawal' => 'Withdrawal', 'adjustment' => 'Adjustment',
        'dividend' => 'Dividend', 'profit' => 'Investment profit share', 'charge' => 'Fee', 'transfer' => 'Internal transfer', 'subscription' => 'Annual subscription', 'membership_fee' => 'Membership fee',
    ];

    /** GET /api/me/receipts?page= */
    public function mine(Request $request): never
    {
        $this->list((int) Auth::id(), $request);
    }

    /** GET /api/members/{id}/receipts?page= (admin) */
    public function member(Request $request): never
    {
        $this->list((int) $request->param('id'), $request);
    }

    /** GET /api/receipts/{no} - the member it belongs to, or the office */
    public function show(Request $request): never
    {
        if (!preg_match('/^RCT-([TLF])(\d{1,12})$/', $request->param('no'), $m)) {
            Response::error('Receipt not found.', 404);
        }
        $rows = match ($m[1]) {
            'T' => $this->transactions('t.id = ?', [(int) $m[2]]),
            'L' => $this->payments('p.id = ?', [(int) $m[2]]),
            'F' => $this->fees('f.id = ?', [(int) $m[2]]),
        };
        $receipt = $rows[0] ?? Response::error('Receipt not found.', 404);
        $user = Auth::user();
        if ($receipt['user_id'] !== (int) $user['id'] && !Roles::allows($user['role'], [Roles::ADMIN])) {
            Response::error('Receipt not found.', 404);
        }
        Response::ok(['receipt' => $receipt, 'member' => $this->memberInfo($receipt['user_id'])]);
    }

    private function list(int $userId, Request $request): never
    {
        $rows = [
            ...$this->transactions('t.user_id = ?', [$userId]),
            ...$this->payments('l.user_id = ?', [$userId]),
            ...$this->fees('f.user_id = ?', [$userId]),
        ];
        usort($rows, fn ($a, $b) => [$b['date'], $b['no']] <=> [$a['date'], $a['no']]);
        $page = max(1, (int) $request->query('page', 1));
        $per = 30;
        Response::ok(['receipts' => array_slice($rows, ($page - 1) * $per, $per), 'total' => count($rows), 'page' => $page, 'per_page' => $per, 'member' => $this->memberInfo($userId)]);
    }

    private function memberInfo(int $id): array
    {
        $stmt = Database::tenant()->prepare("SELECT full_name, account_no, employee_no FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: Response::error('Member not found.', 404);
    }

    private function transactions(string $where, array $args): array
    {
        $stmt = Database::tenant()->prepare(
            "SELECT t.*, c.full_name AS recorded_by, r.id AS reversed_id, w.fee, w.fee_on_top, w.cheque_no
             FROM transactions t LEFT JOIN users c ON c.id = t.created_by
             LEFT JOIN transactions r ON r.reversal_of = t.id
             LEFT JOIN withdrawal_requests w ON w.transaction_id = t.id
             WHERE $where AND t.type <> 'reversal' ORDER BY t.id DESC"
        );
        $stmt->execute($args);
        return array_map(function ($t) {
            $cents = Money::fromDecimal($t['amount']);
            $lines = [];
            $fee = Money::fromDecimal($t['fee'] ?? null);
            if ($t['type'] === 'withdrawal' && $fee > 0 && !$t['fee_on_top']) {
                $lines = [['label' => 'Taken from savings', 'amount' => Money::toDecimal(abs($cents))], ['label' => 'Withdrawal fee', 'amount' => Money::toDecimal($fee)], ['label' => 'Cheque paid', 'amount' => Money::toDecimal(abs($cents) - $fee)]];
            }
            return [
                'no' => 'RCT-T' . str_pad((string) $t['id'], 6, '0', STR_PAD_LEFT),
                'user_id' => (int) $t['user_id'],
                'date' => $t['value_date'],
                'direction' => $cents >= 0 ? 'in' : 'out',
                'title' => self::TITLES[$t['type']] ?? ucfirst($t['type']),
                'account' => $t['account'] === 'shares' ? 'Share capital' : 'Savings',
                'amount' => Money::toDecimal(abs($cents)),
                'reference' => $t['reference'],
                'details' => $t['narration'],
                'balance_after' => $t['balance_after'],
                'recorded_by' => $t['recorded_by'],
                'reversed' => (bool) $t['reversed_id'],
                'lines' => $lines,
            ];
        }, $stmt->fetchAll());
    }

    private function payments(string $where, array $args): array
    {
        $db = Database::tenant();
        $stmt = $db->prepare(
            "SELECT p.*, l.loan_no, l.user_id, c.full_name AS recorded_by, r.id AS reversed_id
             FROM loan_payments p JOIN loans l ON l.id = p.loan_id LEFT JOIN users c ON c.id = p.created_by
             LEFT JOIN loan_payments r ON r.reversal_of = p.id
             WHERE $where AND p.reversal_of IS NULL ORDER BY p.id DESC"
        );
        $stmt->execute($args);
        $names = ['principal' => 'Principal', 'interest' => 'Interest', 'penalty' => 'Penalties', 'fee' => 'Fees'];
        $split = $db->prepare('SELECT part, SUM(amount) FROM loan_payment_allocations WHERE payment_id = ? GROUP BY part');
        return array_map(function ($p) use ($split, $names) {
            $split->execute([$p['id']]);
            $lines = [];
            foreach ($split->fetchAll(\PDO::FETCH_KEY_PAIR) as $part => $amount) {
                $lines[] = ['label' => $names[$part] ?? ucfirst($part), 'amount' => $amount];
            }
            return [
                'no' => 'RCT-L' . str_pad((string) $p['id'], 6, '0', STR_PAD_LEFT),
                'user_id' => (int) $p['user_id'],
                'date' => $p['paid_on'],
                'direction' => 'in',
                'title' => 'Loan repayment',
                'account' => 'Loan ' . $p['loan_no'],
                'amount' => $p['amount'],
                'reference' => $p['reference'],
                'details' => $p['narration'],
                'balance_after' => null,
                'recorded_by' => $p['recorded_by'],
                'reversed' => (bool) $p['reversed_id'],
                'lines' => $lines,
            ];
        }, $stmt->fetchAll());
    }

    private function fees(string $where, array $args): array
    {
        $stmt = Database::tenant()->prepare(
            "SELECT f.*, c.full_name AS recorded_by FROM membership_fees f LEFT JOIN users c ON c.id = f.paid_by
             WHERE $where AND f.status = 'paid' AND f.method = 'cash' ORDER BY f.id DESC"
        );
        $stmt->execute($args);
        return array_map(fn ($f) => [
            'no' => 'RCT-F' . str_pad((string) $f['id'], 6, '0', STR_PAD_LEFT),
            'user_id' => (int) $f['user_id'],
            'date' => $f['paid_on'],
            'direction' => 'in',
            'title' => "Membership fee {$f['year']}",
            'account' => 'Paid in cash',
            'amount' => $f['amount'],
            'reference' => $f['reference'],
            'details' => null,
            'balance_after' => null,
            'recorded_by' => $f['recorded_by'],
            'reversed' => false,
            'lines' => [],
        ], $stmt->fetchAll());
    }
}
