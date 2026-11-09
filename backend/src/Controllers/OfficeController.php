<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Activity;
use Sacco\Support\Money;
use Sacco\Support\Settings;

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

        $months = self::months();
        $funds = self::fundsTrend($db);

        // Loan repayments collected each month
        $rep = $db->prepare("SELECT DATE_FORMAT(paid_on, '%Y-%m') AS m, SUM(amount) FROM loan_payments WHERE paid_on >= ? GROUP BY m");
        $rep->execute([$months[0] . '-01']);
        $repaid = $rep->fetchAll(\PDO::FETCH_KEY_PAIR);
        $collections = array_map(fn ($m) => ['month' => $m, 'value' => Money::toDecimal(Money::fromDecimal($repaid[$m] ?? null))], $months);

        // Loans by where they stand
        $byStatus = $db->query('SELECT status, COUNT(*) AS n, SUM(amount) AS amount FROM loans GROUP BY status')->fetchAll(\PDO::FETCH_UNIQUE);

        // Latest activity, in words
        $recent = $db->query(
            'SELECT a.action, a.subject_type, a.subject_id, a.details, a.created_at, u.full_name AS actor, u.role AS actor_role
             FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT 30'
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
                'text' => Activity::describe($r),
                'actor' => $r['actor'] ?? (str_starts_with($r['action'], 'platform.') ? 'System provider' : 'System'),
                'actor_role' => $r['actor_role'],
                'action' => $r['action'],
                'link' => Activity::link($r),
                'at' => $r['created_at'],
            ], $recent),
        ]);
    }

    /** GET /api/office/sections - the figures on each section's dashboard */
    public function sections(Request $request): never
    {
        $db = Database::tenant();
        $one = function (string $sql, array $args = []) use ($db) {
            $s = $db->prepare($sql);
            $s->execute($args);
            return $s->fetchColumn();
        };
        $month = date('Y-m-01');
        $year = (int) date('Y');
        \Sacco\Support\Books::sync($db);
        $totals = \Sacco\Support\Books::totals($db, date('Y-m-d'));
        $dr = $cr = 0;
        foreach ($totals as $t) {
            $dr += $t['d'];
            $cr += $t['c'];
        }
        $yearTotals = \Sacco\Support\Books::totals($db, date('Y-m-d'), "$year-01-01", false);
        $income = $expense = 0;
        foreach (\Sacco\Support\Books::chart($db) as $a) {
            if ($a['type'] === 'income') {
                $income += \Sacco\Support\Books::balance($a, $yearTotals);
            } elseif ($a['type'] === 'expense') {
                $expense += \Sacco\Support\Books::balance($a, $yearTotals);
            }
        }
        $outstanding = 0;
        foreach ($db->query("SELECT id FROM loans WHERE status = 'active'")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $outstanding += Loans::position($db, (int) $id)['total_left'];
        }
        $money = fn ($v) => Money::toDecimal(Money::fromDecimal($v));
        Response::ok([
            'waiting' => self::waiting(),
            'deposits_month' => $money($one("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'deposit' AND value_date >= ?", [$month])),
            'transfers_month' => (int) $one("SELECT COUNT(*) FROM transfers WHERE status = 'posted' AND value_date >= ?", [$month]),
            'transactions_month' => (int) $one('SELECT COUNT(*) FROM transactions WHERE value_date >= ?', [$month]),
            'fees_unpaid' => (int) $one("SELECT COUNT(*) FROM membership_fees WHERE year = ? AND status = 'unpaid'", [$year]),
            'fees_not_charged' => (int) $one("SELECT COUNT(*) FROM users u WHERE u.role = 'member' AND u.status = 'active' AND NOT EXISTS (SELECT 1 FROM membership_fees f WHERE f.user_id = u.id AND f.year = ?)", [$year]),
            'fee_set' => (float) Settings::get('membership_fee_annual', '0') > 0,
            'members' => (int) $one("SELECT COUNT(*) FROM users WHERE role = 'member' AND status = 'active'"),
            'last_payroll' => $one("SELECT period FROM payroll_batches WHERE status = 'posted' ORDER BY period DESC LIMIT 1") ?: null,
            'active_loans' => (int) $one("SELECT COUNT(*) FROM loans WHERE status = 'active'"),
            'loans_outstanding' => Money::toDecimal($outstanding),
            'interest_month' => $money($one("SELECT COALESCE(SUM(interest), 0) FROM loan_installments i JOIN loans l ON l.id = i.loan_id WHERE l.status IN ('active', 'closed') AND i.due_date BETWEEN ? AND LAST_DAY(?)", [$month, $month])),
            'payments_month' => $money($one("SELECT COALESCE(SUM(amount), 0) FROM cashbook WHERE kind = 'expense' AND entry_date >= ?", [$month])),
            'receipts_month' => $money($one("SELECT COALESCE(SUM(amount), 0) FROM cashbook WHERE kind = 'income' AND entry_date >= ?", [$month])),
            'journals_month' => (int) $one('SELECT COUNT(*) FROM journal_entries WHERE auto = 0 AND entry_date >= ?', [$month]),
            'balanced' => $dr === $cr,
            'income_year' => Money::toDecimal($income),
            'surplus_year' => Money::toDecimal($income - $expense),
            'accounts' => (int) $one("SELECT COUNT(*) FROM gl_accounts WHERE status = 'active'"),
            'last_backup' => $one("SELECT MAX(created_at) FROM audit_log WHERE action = 'backup.downloaded'") ?: null,
            'investments' => $money($one("SELECT COALESCE(SUM(amount), 0) FROM investments WHERE status = 'active'")),
        ]);
    }

    /** The last 12 months, oldest first, as YYYY-MM */
    public static function months(int $count = 12): array
    {
        $months = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $months[] = date('Y-m', strtotime("first day of -$i month"));
        }
        return $months;
    }

    /**
     * Savings + shares at each of the last 12 month-ends: for the whole SACCO, or one member and
     * one account when given.
     */
    public static function fundsTrend(\PDO $db, ?int $userId = null, ?string $account = null): array
    {
        $months = self::months();
        $where = '1 = 1';
        $args = [];
        if ($userId) {
            $where .= ' AND user_id = ?';
            $args[] = $userId;
        }
        if ($account) {
            $where .= ' AND account = ?';
            $args[] = $account;
        }
        $before = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE $where AND value_date < ?");
        $before->execute([...$args, $months[0] . '-01']);
        $byMonth = $db->prepare("SELECT DATE_FORMAT(value_date, '%Y-%m') AS m, SUM(amount) FROM transactions WHERE $where AND value_date >= ? GROUP BY m");
        $byMonth->execute([...$args, $months[0] . '-01']);
        $moves = $byMonth->fetchAll(\PDO::FETCH_KEY_PAIR);
        $running = Money::fromDecimal($before->fetchColumn());
        $out = [];
        foreach ($months as $m) {
            $running += Money::fromDecimal($moves[$m] ?? null);
            $out[] = ['month' => $m, 'value' => Money::toDecimal($running)];
        }
        return $out;
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
}
