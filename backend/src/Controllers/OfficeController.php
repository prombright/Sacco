<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Money;

/**
 * The SACCO office at a glance: what's waiting for an admin (also shown as counts in the menu),
 * the headline figures, this month's money in and out, and the trends behind them.
 */
final class OfficeController
{
    /** GET /api/office/counts - things waiting for an admin, for the menu badges */
    public function counts(Request $request): never
    {
        Response::ok(self::waiting());
    }

    /** GET /api/office/overview */
    public function overview(Request $request): never
    {
        $db = Database::tenant();
        $one = fn (string $sql, array $args = []) => (function () use ($db, $sql, $args) {
            $s = $db->prepare($sql);
            $s->execute($args);
            return $s->fetchColumn();
        })();

        // Headline totals
        $savings = Money::fromDecimal($one("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account = 'savings'"));
        $shares = Money::fromDecimal($one("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account = 'shares'"));
        $members = (int) $one("SELECT COUNT(*) FROM users WHERE status = 'active' AND role = 'member'");
        $newMembers = (int) $one("SELECT COUNT(*) FROM users WHERE role = 'member' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");

        $outstanding = 0;
        $arrears = 0;
        $arrearsCount = 0;
        $active = $db->query("SELECT id FROM loans WHERE status = 'active'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($active as $loanId) {
            $p = Loans::position($db, (int) $loanId);
            $outstanding += $p['total_left'];
            if ($p['overdue'] > 0) {
                $arrears += $p['overdue'];
                $arrearsCount++;
            }
        }

        // This month's money, by kind
        $monthStart = date('Y-m-01');
        // Entries later reversed don't count
        $sumTx = fn (string $where) => Money::fromDecimal($one(
            "SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
             WHERE t.value_date >= ? AND $where AND NOT EXISTS (SELECT 1 FROM transactions r WHERE r.reversal_of = t.id)",
            [$monthStart]
        ));
        $thisMonth = [
            'savings_in' => $sumTx("t.account = 'savings' AND t.type IN ('deposit', 'opening_balance')"),
            'shares_in' => $sumTx("t.account = 'shares' AND t.type IN ('deposit', 'opening_balance')"),
            'withdrawn' => -$sumTx("t.account = 'savings' AND t.type = 'withdrawal'"),
            'dividends' => $sumTx("t.type = 'dividend'"),
            'repaid' => Money::fromDecimal($one('SELECT COALESCE(SUM(amount), 0) FROM loan_payments WHERE paid_on >= ?', [$monthStart])),
            'lent' => Money::fromDecimal($one('SELECT COALESCE(SUM(amount), 0) FROM loans WHERE disbursed_on >= ?', [$monthStart])),
        ];

        // Member funds (savings + shares) at each of the last 12 month-ends
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[] = date('Y-m', strtotime("first day of -$i month"));
        }
        $before = Money::fromDecimal($one('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE value_date < ?', [$months[0] . '-01']));
        $byMonth = $db->prepare("SELECT DATE_FORMAT(value_date, '%Y-%m') AS m, SUM(amount) FROM transactions WHERE value_date >= ? GROUP BY m");
        $byMonth->execute([$months[0] . '-01']);
        $moves = $byMonth->fetchAll(\PDO::FETCH_KEY_PAIR);
        $running = $before;
        $funds = [];
        foreach ($months as $m) {
            $running += Money::fromDecimal($moves[$m] ?? null);
            $funds[] = ['month' => $m, 'value' => Money::toDecimal($running)];
        }

        // Loan repayments collected each month
        $rep = $db->prepare("SELECT DATE_FORMAT(paid_on, '%Y-%m') AS m, SUM(amount) FROM loan_payments WHERE paid_on >= ? GROUP BY m");
        $rep->execute([$months[0] . '-01']);
        $repaid = $rep->fetchAll(\PDO::FETCH_KEY_PAIR);
        $collections = array_map(fn ($m) => ['month' => $m, 'value' => Money::toDecimal(Money::fromDecimal($repaid[$m] ?? null))], $months);

        // Loans by where they stand
        $byStatus = $db->query('SELECT status, COUNT(*) AS n, SUM(amount) AS amount FROM loans GROUP BY status')->fetchAll(\PDO::FETCH_UNIQUE);

        // Latest activity, in words
        $recent = $db->query(
            'SELECT a.action, a.subject_type, a.subject_id, a.details, a.created_at, u.full_name AS actor
             FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT 8'
        )->fetchAll();

        Response::ok([
            'totals' => [
                'savings' => Money::toDecimal($savings),
                'shares' => Money::toDecimal($shares),
                'members' => $members,
                'new_members_this_month' => $newMembers,
                'loans_outstanding' => Money::toDecimal($outstanding),
                'active_loans' => count($active),
                'arrears' => Money::toDecimal($arrears),
                'loans_in_arrears' => $arrearsCount,
            ],
            'this_month' => array_map([Money::class, 'toDecimal'], $thisMonth),
            'waiting' => self::waiting(),
            'funds' => $funds,
            'collections' => $collections,
            'loans_by_status' => array_map(fn ($r) => ['count' => (int) $r['n'], 'amount' => $r['amount']], $byStatus),
            'recent' => array_map(fn ($r) => [
                'text' => self::describe($r),
                'actor' => $r['actor'] ?? 'System',
                'at' => $r['created_at'],
            ], $recent),
        ]);
    }

    /** Things an admin needs to act on */
    public static function waiting(): array
    {
        $counts = Database::tenant()->query(
            "SELECT
                (SELECT COUNT(*) FROM loans WHERE status = 'pending_approval') AS loans_to_approve,
                (SELECT COUNT(*) FROM loans WHERE status = 'approved') AS loans_to_pay_out,
                (SELECT COUNT(*) FROM loans WHERE status = 'pending_guarantors') AS loans_waiting_guarantors,
                (SELECT COUNT(*) FROM withdrawal_requests WHERE status = 'pending') AS withdrawals_to_approve,
                (SELECT COUNT(*) FROM withdrawal_requests WHERE status = 'approved') AS withdrawals_to_pay,
                (SELECT COUNT(*) FROM users WHERE status = 'active' AND role = 'member' AND must_change_password = 1) AS members_not_signed_in"
        )->fetch();
        return array_map('intval', $counts);
    }

    /** An audit row as a short sentence */
    private static function describe(array $r): string
    {
        $d = $r['details'] ? json_decode($r['details'], true) : [];
        $amount = fn (string $k) => isset($d[$k]) ? number_format((float) $d[$k]) : '';
        return match ($r['action']) {
            'loan.applied' => "applied for a loan of {$amount('amount')}",
            'loan.approved' => 'approved a loan',
            'loan.rejected' => 'rejected a loan',
            'loan.disbursed' => 'paid out a loan',
            'loan.payment_recorded' => "recorded a loan repayment of {$amount('amount')}",
            'loan.guarantee_accepted' => 'agreed to guarantee a loan',
            'loan.guarantee_declined' => 'declined to guarantee a loan',
            'withdrawal.requested' => "asked to withdraw {$amount('amount')}",
            'withdrawal.approved' => 'approved a withdrawal',
            'withdrawal.paid' => 'issued a withdrawal cheque',
            'payroll.posted' => 'posted the payroll for ' . ($d['period'] ?? ''),
            'payroll.reversed' => 'reversed a payroll upload',
            'dividend.posted' => 'credited the ' . ($d['label'] ?? '') . ' dividend',
            'transaction.recorded' => 'recorded a ' . str_replace('_', ' ', $d['type'] ?? 'entry') . " of {$amount('amount')}",
            'transaction.reversed' => 'reversed an entry',
            'user.created' => 'added a member',
            'users.imported' => 'imported ' . ($d['count'] ?? '') . ' members',
            'settings.changed' => 'changed the SACCO rules',
            'password.changed' => 'changed their password',
            default => str_replace(['.', '_'], [' ', ' '], $r['action']),
        };
    }
}
