<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Roles;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Ledger;
use Sacco\Support\Books;
use Sacco\Support\Money;
use Sacco\Support\AuditView;
use Sacco\Support\Activity;
use Sacco\Support\Savings;

/**
 * Reports for the office and the committee. Each returns
 * { title, subtitle, columns: [{ key, label, type }], rows, totals } - the browser shows it and
 * turns it into an Excel file, so adding a report means adding one method here and one line in
 * REPORTS. Column types: text, money, date, number.
 */
final class ReportController
{
    public const REPORTS = [
        'members' => ['Members summary', 'Savings, shares, pledges and loans for every member, as of now.', false],
        'loans' => ['Loan portfolio', 'Every loan not yet repaid: what was lent, what is still owed, and what is late.', false],
        'arrears' => ['Loans in arrears', 'Active loans with instalments past their due date.', false],
        'journal' => ['Transactions journal', 'Every savings, shares and loan entry in a date range.', true],
        'audit' => ['Audit log', 'Who did what in the system, in a date range.', true],
    ];

    /** GET /api/reports - the list */
    public function index(Request $request): never
    {
        $list = [];
        foreach (self::REPORTS as $key => [$title, $description, $dated]) {
            $list[] = ['key' => $key, 'title' => $title, 'description' => $description, 'needs_dates' => $dated];
        }
        Response::ok(['reports' => $list]);
    }

    /** GET /api/reports/{name}?from=&to= */
    public function show(Request $request): never
    {
        $name = $request->param('name');
        if (!isset(self::REPORTS[$name])) {
            Response::error('No such report.', 404);
        }
        [$title, , $dated] = self::REPORTS[$name];
        $from = $to = null;
        if ($dated) {
            $from = $this->date($request->string('from') ?: date('Y-m-01'));
            $to = $this->date($request->string('to') ?: date('Y-m-d'));
            if ($from > $to) {
                Response::error('The start date is after the end date.', 422);
            }
        }
        $report = $this->$name($from, $to);
        Response::ok($report + [
            'title' => $title,
            'subtitle' => $dated ? date('j M Y', strtotime($from)) . ' to ' . date('j M Y', strtotime($to)) : 'As of ' . date('j M Y, H:i'),
        ]);
    }

    private function members(): array
    {
        $db = Database::tenant();
        $users = $db->query("SELECT id, account_no, full_name, employee_no, status, joined_on FROM users WHERE role = 'member' ORDER BY full_name")->fetchAll();
        $rows = [];
        foreach ($users as $u) {
            $id = (int) $u['id'];
            $savings = Ledger::balance($db, $id, 'savings');
            $shares = Ledger::balance($db, $id, 'shares');
            $loan = 0;
            $stmt = $db->prepare("SELECT id FROM loans WHERE user_id = ? AND status = 'active'");
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $loanId) {
                $loan += Loans::position($db, (int) $loanId)['total_left'];
            }
            if ($savings === 0 && $shares === 0 && $loan === 0 && $u['status'] !== 'active') {
                continue; // long-gone members with nothing left
            }
            $rows[] = [
                'account_no' => $u['account_no'],
                'full_name' => $u['full_name'],
                'employee_no' => $u['employee_no'],
                'status' => ucfirst($u['status']),
                'member_since' => $u['joined_on'] ?? Loans::membership($db, $id)['since'],
                'savings' => $savings,
                'pledged' => Loans::pledged($db, $id),
                'free' => Savings::free($db, $id),
                'shares' => $shares,
                'share_count' => ($price = (int) Money::parse(\Sacco\Support\Settings::get('share_price', '0'))) > 0 ? intdiv($shares, $price) : null,
                'loan' => $loan,
            ];
        }
        return $this->finish([
            ['account_no', 'Account no.', 'text'],
            ['full_name', 'Name', 'text'],
            ['employee_no', 'Employee no.', 'text'],
            ['status', 'Status', 'text'],
            ['member_since', 'Member since', 'date'],
            ['savings', 'Savings', 'money'],
            ['pledged', 'Pledged', 'money'],
            ['free', 'Free savings', 'money'],
            ['shares', 'Share capital', 'money'],
            ['share_count', 'Shares', 'number'],
            ['loan', 'Loan balance', 'money'],
        ], $rows);
    }

    private function loans(): array
    {
        return $this->loanRows(false);
    }

    private function arrears(): array
    {
        return $this->loanRows(true);
    }

    private function loanRows(bool $arrearsOnly): array
    {
        $db = Database::tenant();
        $loans = $db->query(
            "SELECT l.*, u.full_name, u.account_no FROM loans l JOIN users u ON u.id = l.user_id
             WHERE l.status IN ('pending_guarantors', 'pending_approval', 'approved', 'active') ORDER BY u.full_name, l.id"
        )->fetchAll();
        $oldest = $db->prepare(
            'SELECT MIN(due_date) FROM loan_installments WHERE loan_id = ? AND due_date < CURDATE() AND principal + interest > principal_paid + interest_paid'
        );
        $labels = ['pending_guarantors' => 'Waiting for guarantors', 'pending_approval' => 'Waiting for approval', 'approved' => 'Approved', 'active' => 'Active'];

        $rows = [];
        foreach ($loans as $l) {
            $active = $l['status'] === 'active';
            $p = $active ? Loans::position($db, (int) $l['id']) : null;
            $daysLate = null;
            if ($p && $p['overdue'] > 0) {
                $oldest->execute([$l['id']]);
                $daysLate = (int) (new \DateTimeImmutable((string) $oldest->fetchColumn()))->diff(new \DateTimeImmutable('today'))->days;
            }
            if ($arrearsOnly && !$daysLate) {
                continue;
            }
            $rows[] = [
                'loan_no' => $l['loan_no'],
                'account_no' => $l['account_no'],
                'full_name' => $l['full_name'],
                'status' => $labels[$l['status']],
                'amount' => Money::fromDecimal($l['amount']),
                'months' => (int) $l['months'],
                'rate' => (float) $l['interest_rate_monthly'],
                'disbursed_on' => $l['disbursed_on'],
                'principal_left' => $p['principal_left'] ?? null,
                'interest_left' => $p['interest_left'] ?? null,
                'total_left' => $p['total_left'] ?? null,
                'next_due' => $p['next_due_date'] ?? null,
                'overdue' => $p['overdue'] ?? null,
                'days_late' => $daysLate,
            ];
        }
        if ($arrearsOnly) {
            usort($rows, fn ($a, $b) => $b['days_late'] <=> $a['days_late']);
        }
        return $this->finish([
            ['loan_no', 'Loan no.', 'text'],
            ['account_no', 'Account no.', 'text'],
            ['full_name', 'Borrower', 'text'],
            ['status', 'Status', 'text'],
            ['amount', 'Amount lent', 'money'],
            ['months', 'Months', 'number'],
            ['rate', '% a month', 'number'],
            ['disbursed_on', 'Paid out', 'date'],
            ['principal_left', 'Principal owed', 'money'],
            ['interest_left', 'Interest owed', 'money'],
            ['total_left', 'Total owed', 'money'],
            ['next_due', 'Next due', 'date'],
            ['overdue', 'Overdue', 'money'],
            ['days_late', 'Days late', 'number'],
        ], $rows);
    }

    /** Income less expenses for the period, line by line */
    private function income(string $from, string $to): array
    {
        $loan = CashbookController::loanIncome($from, $to);
        $stmt = Database::tenant()->prepare('SELECT kind, category, SUM(amount) AS total FROM cashbook WHERE entry_date BETWEEN ? AND ? GROUP BY kind, category ORDER BY kind, category');
        $stmt->execute([$from, $to]);
        $book = $stmt->fetchAll();

        $rows = [];
        $income = 0;
        foreach ([['Loan interest received', $loan['interest']], ['Late-payment penalties received', $loan['penalties']], ['Loan processing fees', $loan['processing_fees']], ['Withdrawal fees', $loan['withdrawal_fees']]] as [$item, $amount]) {
            $rows[] = ['section' => 'Income', 'item' => $item, 'amount' => Money::fromDecimal($amount)];
            $income += Money::fromDecimal($amount);
        }
        foreach ($book as $b) {
            if ($b['kind'] === 'income' && Money::fromDecimal($b['total']) !== 0) {
                $rows[] = ['section' => 'Income', 'item' => $b['category'], 'amount' => Money::fromDecimal($b['total'])];
                $income += Money::fromDecimal($b['total']);
            }
        }
        $rows[] = ['section' => 'Income', 'item' => 'Total income', 'amount' => $income];
        $expenses = 0;
        foreach ($book as $b) {
            if ($b['kind'] === 'expense' && Money::fromDecimal($b['total']) !== 0) {
                $rows[] = ['section' => 'Expenses', 'item' => $b['category'], 'amount' => Money::fromDecimal($b['total'])];
                $expenses += Money::fromDecimal($b['total']);
            }
        }
        $rows[] = ['section' => 'Expenses', 'item' => 'Total expenses', 'amount' => $expenses];
        $rows[] = ['section' => 'Result', 'item' => $income >= $expenses ? 'Surplus' : 'Deficit', 'amount' => $income - $expenses];

        return $this->finish([
            ['section', 'Section', 'text'],
            ['item', 'Item', 'text'],
            ['amount', 'Amount', 'money'],
        ], $rows, totals: false);
    }

    private function journal(string $from, string $to): array
    {
        $db = Database::tenant();
        $stmt = $db->prepare(
            "SELECT t.value_date AS date, t.created_at, u.account_no, u.full_name, t.account, t.type, t.amount, t.reference, t.narration, CASE WHEN c.role = 'super_admin' AND c.office_via_member = 0 THEN 'System administrator' ELSE c.full_name END AS recorded_by
             FROM transactions t JOIN users u ON u.id = t.user_id LEFT JOIN users c ON c.id = t.created_by
             WHERE t.value_date BETWEEN ? AND ?
             UNION ALL
             SELECT p.paid_on, p.created_at, u.account_no, u.full_name, 'loan', IF(p.reversal_of IS NULL, 'repayment', 'reversal'), p.amount,
                    p.reference, CONCAT(l.loan_no, IFNULL(CONCAT(' · ', p.narration), '')), c.full_name
             FROM loan_payments p JOIN loans l ON l.id = p.loan_id JOIN users u ON u.id = l.user_id LEFT JOIN users c ON c.id = p.created_by
             WHERE p.paid_on BETWEEN ? AND ?
             UNION ALL
             SELECT l.disbursed_on, l.updated_at, u.account_no, u.full_name, 'loan', 'payout', -l.amount, l.disbursement_ref, l.loan_no, c.full_name
             FROM loans l JOIN users u ON u.id = l.user_id LEFT JOIN users c ON c.id = l.disbursed_by
             WHERE l.disbursed_on BETWEEN ? AND ?
             ORDER BY date, created_at"
        );
        $stmt->execute([$from, $to, $from, $to, $from, $to]);
        $types = ['opening_balance' => 'Opening balance', 'deposit' => 'Deposit', 'withdrawal' => 'Withdrawal', 'adjustment' => 'Adjustment',
            'dividend' => 'Dividend', 'reversal' => 'Reversal', 'repayment' => 'Repayment', 'payout' => 'Loan paid out'];

        $rows = array_map(fn ($r) => [
            'date' => $r['date'],
            'account_no' => $r['account_no'],
            'full_name' => $r['full_name'],
            'account' => ucfirst($r['account']),
            'type' => $types[$r['type']] ?? $r['type'],
            'amount' => Money::fromDecimal($r['amount']),
            'reference' => $r['reference'],
            'narration' => $r['narration'],
            'recorded_by' => $r['recorded_by'],
        ], $stmt->fetchAll());

        return $this->finish([
            ['date', 'Date', 'date'],
            ['account_no', 'Account no.', 'text'],
            ['full_name', 'Member', 'text'],
            ['account', 'Account', 'text'],
            ['type', 'Entry', 'text'],
            ['amount', 'Amount', 'money'],
            ['reference', 'Reference', 'text'],
            ['narration', 'Note', 'text'],
            ['recorded_by', 'Recorded by', 'text'],
        ], $rows, totals: false);
    }

    private function audit(string $from, string $to): array
    {
        $db = Database::tenant();
        $rows = array_map(fn ($e) => [
            'when' => $e['at'],
            'actor' => $e['who'],
            'action' => $e['what'],
            'subject' => $e['on'],
            'details' => implode(' · ', $e['details']),
            'ip' => $e['from'],
        ], self::auditEntries($db, $from, $to));

        return $this->finish([
            ['when', 'When', 'text'],
            ['actor', 'Who', 'text'],
            ['action', 'What', 'text'],
            ['subject', 'On', 'text'],
            ['details', 'Details', 'text'],
            ['ip', 'From (internet address)', 'text'],
        ], $rows, totals: false);
    }

    /** Every audit entry in a period, oldest first, in words (AuditView) */
    public static function auditEntries(\PDO $db, string $from, string $to): array
    {
        $stmt = $db->prepare(
            'SELECT a.id, a.created_at, a.actor_id, ' . Roles::shownNameSql('u') . ' AS actor, u.role AS actor_role, a.action, a.subject_type, a.subject_id, a.details, a.ip
             FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id
             WHERE a.created_at >= ? AND a.created_at < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY a.id'
        );
        $stmt->execute([$from, $to]);
        return array_map(function ($r) use ($db) {
            $d = $r['details'] ? json_decode($r['details'], true) : null;
            $d = is_array($d) ? $d : null;
            return [
                'id' => (int) $r['id'],
                'at' => $r['created_at'],
                'actor_id' => $r['actor_id'] !== null ? (int) $r['actor_id'] : null,
                'who' => AuditView::who($r['actor'], $r['actor_role']),
                'action' => $r['action'],
                'area' => AuditView::area($r['action']),
                'sensitive' => AuditView::sensitive($r['action'], $d),
                'routine' => AuditView::routine($r['action']),
                'what' => ucfirst(Activity::describe($r)),
                'on' => AuditView::subject($db, $r['subject_type'], $r['subject_id'] !== null ? (int) $r['subject_id'] : null),
                'link' => Activity::link($r),
                'details' => AuditView::lines($db, $d),
                'from' => AuditView::from($r['ip']),
            ];
        }, $stmt->fetchAll());
    }

    /**
     * GET /api/audit?from=&to= (office) - the audit page: every entry in the period, newest first,
     * with its area, whether it needs a look, and the people who made changes.
     */
    public function auditLog(Request $request): never
    {
        $to = $request->string('to') ?: date('Y-m-d');
        $from = $request->string('from') ?: date('Y-m-d', strtotime('-30 days'));
        foreach ([$from, $to] as $d) {
            $p = \DateTime::createFromFormat('!Y-m-d', $d);
            if (!$p || $p->format('Y-m-d') !== $d) {
                Response::error('Choose valid dates.', 422);
            }
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $entries = array_reverse(self::auditEntries(Database::tenant(), $from, $to));
        $people = [];
        foreach ($entries as $e) {
            if ($e['actor_id'] !== null) {
                $people[$e['actor_id']] = $e['who'];
            }
        }
        asort($people);
        Response::ok([
            'from' => $from,
            'to' => $to,
            'entries' => $entries,
            'areas' => AuditView::AREAS,
            'people' => array_map(fn ($id, $name) => ['id' => $id, 'name' => $name], array_keys($people), $people),
        ]);
    }

    /**
     * The loan book as it stood on a day: each running loan's principal still owed then, and how
     * late it was then (from its oldest instalment not yet fully paid on that day).
     *
     * @return array<int, array{id: int, loan_no: string, borrower: string, product: string, principal: int, days: int}>
     */
    public static function bookOn(\PDO $db, string $day): array
    {
        $stmt = $db->prepare(
            "SELECT l.id, l.loan_no, l.amount, u.full_name, COALESCE(p.name, 'Loan') AS product,
                    COALESCE((SELECT SUM(a.amount) FROM loan_payment_allocations a JOIN loan_payments lp ON lp.id = a.payment_id
                              WHERE a.loan_id = l.id AND a.part = 'principal' AND lp.paid_on <= ?), 0) AS principal_paid,
                    (SELECT MIN(i.due_date) FROM loan_installments i
                      WHERE i.loan_id = l.id AND i.due_date < ? AND (i.cleared_on IS NULL OR i.cleared_on > ?)) AS oldest_due
             FROM loans l JOIN users u ON u.id = l.user_id LEFT JOIN loan_products p ON p.id = l.product_id
             WHERE l.status IN ('active', 'closed') AND l.disbursed_on <= ? AND (l.closed_on IS NULL OR l.closed_on > ?)"
        );
        $stmt->execute([$day, $day, $day, $day, $day]);
        $on = new \DateTimeImmutable($day);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $principal = Money::fromDecimal($r['amount']) - Money::fromDecimal((string) $r['principal_paid']);
            if ($principal <= 0) {
                continue;
            }
            $out[] = [
                'id' => (int) $r['id'],
                'loan_no' => $r['loan_no'],
                'borrower' => $r['full_name'],
                'product' => $r['product'],
                'principal' => $principal,
                'days' => $r['oldest_due'] ? (int) (new \DateTimeImmutable($r['oldest_due']))->diff($on)->days : 0,
            ];
        }
        return $out;
    }

    /** Share of the principal in loans more than $days late, as a percent with one decimal */
    public static function parOf(array $book, int $days): float
    {
        $total = array_sum(array_column($book, 'principal'));
        $late = array_sum(array_map(fn ($l) => $l['days'] > $days ? $l['principal'] : 0, $book));
        return $total > 0 ? round($late * 100 / $total, 1) : 0.0;
    }

    /**
     * GET /api/reports/portfolio-risk?as_of= (office) - portfolio at risk: how much of the loan book
     * is late and how badly, by loan type, the most-late loans, and PAR30 at each of the last 12 month ends.
     */
    public function portfolioRisk(Request $request): never
    {
        $asOf = $request->string('as_of') ?: date('Y-m-d');
        $d = \DateTime::createFromFormat('!Y-m-d', $asOf);
        if (!$d || $d->format('Y-m-d') !== $asOf) {
            Response::error('Choose a valid date.', 422);
        }
        $db = Database::tenant();
        $book = self::bookOn($db, $asOf);
        $total = array_sum(array_column($book, 'principal'));
        $bands = [
            ['current', 'Not late', 0, 0],
            ['1-30', '1 to 30 days late', 1, 30],
            ['31-60', '31 to 60 days', 31, 60],
            ['61-90', '61 to 90 days', 61, 90],
            ['91-180', '91 to 180 days', 91, 180],
            ['180+', 'Over 180 days', 181, PHP_INT_MAX],
        ];
        $buckets = array_map(function ($b) use ($book, $total) {
            $in = array_filter($book, fn ($l) => $l['days'] >= $b[2] && $l['days'] <= $b[3]);
            $p = array_sum(array_column($in, 'principal'));
            return ['key' => $b[0], 'label' => $b[1], 'loans' => count($in), 'principal' => Money::toDecimal($p), 'share' => $total ? round($p * 100 / $total, 1) : 0];
        }, $bands);
        $products = [];
        foreach ($book as $l) {
            $products[$l['product']][] = $l;
        }
        ksort($products);
        $byProduct = array_map(fn ($name, $loans) => [
            'name' => $name,
            'loans' => count($loans),
            'principal' => Money::toDecimal(array_sum(array_column($loans, 'principal'))),
            'par30' => self::parOf($loans, 30),
        ], array_keys($products), $products);
        $late = array_values(array_filter($book, fn ($l) => $l['days'] > 0));
        usort($late, fn ($a, $b) => [$b['days'], $b['principal']] <=> [$a['days'], $a['principal']]);
        // PAR30 at each of the last 12 month ends (this month: the chosen day)
        $trend = [];
        for ($m = 11; $m >= 0; $m--) {
            $end = $m === 0 ? $asOf : date('Y-m-t', strtotime(date('Y-m-01', strtotime($asOf)) . " -$m month"));
            $b = self::bookOn($db, $end);
            // From the month the loan book starts: no empty months before it
            if ($b || $trend) {
                $trend[] = ['month' => substr($end, 0, 7), 'value' => self::parOf($b, 30), 'principal' => Money::toDecimal(array_sum(array_column($b, 'principal')))];
            }
        }
        // Repayments come off salaries: without the payroll, every loan looks late
        $lastPayroll = $db->query("SELECT MAX(period) FROM payroll_batches WHERE kind = 'payroll' AND status = 'posted'")->fetchColumn() ?: null;
        Response::ok([
            'as_of' => $asOf,
            'last_payroll' => $lastPayroll,
            'payroll_behind' => !$lastPayroll || $lastPayroll < date('Y-m', strtotime(date('Y-m-01', strtotime($asOf)) . ' -1 month')),
            'loans' => count($book),
            'principal' => Money::toDecimal($total),
            'late_loans' => count($late),
            'par' => ['30' => self::parOf($book, 30), '60' => self::parOf($book, 60), '90' => self::parOf($book, 90)],
            'buckets' => $buckets,
            'by_product' => $byProduct,
            'worst' => array_map(fn ($l) => ['id' => $l['id'], 'loan_no' => $l['loan_no'], 'borrower' => $l['borrower'], 'product' => $l['product'], 'days' => $l['days'], 'principal' => Money::toDecimal($l['principal'])], array_slice($late, 0, 25)),
            'trend' => $trend,
        ]);
    }

    /** Money columns go out as decimals, with a total row for them */
    private function finish(array $columns, array $rows, bool $totals = true): array
    {
        $cols = array_map(fn ($c) => ['key' => $c[0], 'label' => $c[1], 'type' => $c[2]], $columns);
        $moneyKeys = array_column(array_filter($cols, fn ($c) => $c['type'] === 'money'), 'key');
        $sum = array_fill_keys($moneyKeys, 0);
        foreach ($rows as &$row) {
            foreach ($moneyKeys as $k) {
                if ($row[$k] !== null) {
                    $sum[$k] += $row[$k];
                    $row[$k] = Money::toDecimal($row[$k]);
                }
            }
        }
        unset($row);
        return [
            'columns' => $cols,
            'rows' => $rows,
            'totals' => $totals && $rows ? array_map([Money::class, 'toDecimal'], $sum) : null,
        ];
    }

    private function date(string $value): string
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            Response::error('Enter valid dates.', 422);
        }
        return $value;
    }

    /** The SACCO's own income, source by source: what each card on the Income page counts */
    private const INCOME = [
        'membership' => ['Membership fees', 'Paid once when someone joins'],
        'subscription' => ['Annual subscriptions', 'Paid by every member each year'],
        'processing' => ['Loan processing fees', 'Taken from savings when a loan is approved'],
        'interest' => ['Loan interest', 'Earned on what members owe, month by month'],
        'investment' => ['Investments', 'Income and gains on the SACCO\'s investments (e.g. unit trusts)'],
    ];

    /** From which date to which: a year, or everything (the year is null) */
    private static function incomeRange(?int $year): array
    {
        return $year ? ["$year-01-01", "$year-12-31"] : ['2000-01-01', date('Y-12-31')];
    }

    /**
     * Each amount of one income source in a period, as [date, cents, from the old records?]
     *
     * @return list<array{0: string, 1: int, 2: bool}>
     */
    private static function incomeItems(\PDO $db, string $key, string $from, string $to): array
    {
        $rows = [];
        if ($key === 'membership' || $key === 'subscription') {
            $s = $db->prepare("SELECT COALESCE(paid_on, DATE(created_at)) AS d, amount, method FROM membership_fees
                               WHERE kind = ? AND status = 'paid' AND COALESCE(paid_on, DATE(created_at)) BETWEEN ? AND ?");
            $s->execute([$key, $from, $to]);
            foreach ($s->fetchAll() as $r) {
                $rows[] = [$r['d'], Money::fromDecimal((string) $r['amount']), $r['method'] === 'old_system'];
            }
        } elseif ($key === 'processing') {
            $s = $db->prepare('SELECT COALESCE(fee_charged_on, disbursed_on) AS d, processing_fee, source FROM loans
                               WHERE processing_fee > 0 AND COALESCE(fee_charged_on, disbursed_on) BETWEEN ? AND ?');
            $s->execute([$from, $to]);
            foreach ($s->fetchAll() as $r) {
                $rows[] = [$r['d'], Money::fromDecimal((string) $r['processing_fee']), $r['source'] === 'old_records'];
            }
        } elseif ($key === 'investment') {
            $s = $db->prepare('SELECT received_on, amount FROM investment_returns r
                               WHERE r.reversal_of IS NULL AND NOT EXISTS (SELECT 1 FROM investment_returns x WHERE x.reversal_of = r.id) AND received_on BETWEEN ? AND ?');
            $s->execute([$from, $to]);
            foreach ($s->fetchAll() as $r) {
                $rows[] = [$r['received_on'], Money::fromDecimal((string) $r['amount']), false];
            }
        } elseif ($key === 'interest') {
            // Interest as the books earn it (each month's interest on what is owed)
            $acc = $db->query("SELECT * FROM gl_accounts WHERE system_key = 'interest_income'")->fetch();
            $first = $db->query("SELECT MIN(disbursed_on) FROM loans WHERE disbursed_on IS NOT NULL")->fetchColumn();
            if ($acc && $first) {
                $m = max(substr($from, 0, 7), substr($first, 0, 7));
                $last = min(substr($to, 0, 7), date('Y-m'));
                while ($m <= $last) {
                    $end = date('Y-m-t', strtotime("$m-01"));
                    $v = Books::balance($acc, Books::totals($db, $end, "$m-01", false));
                    if ($v) {
                        $rows[] = [$end, $v, false];
                    }
                    $m = date('Y-m', strtotime("$m-01 +1 month"));
                }
            }
        }
        return $rows;
    }

    /**
     * GET /api/reports/income?year=YYYY|all (office) - the SACCO's income source by source: the total,
     * how much of it came in with the old records, and month by month
     */
    public function incomeSummary(Request $request): never
    {
        $year = $request->string('year') === 'all' ? null : (int) ($request->string('year') ?: date('Y'));
        if ($year !== null && ($year < 2000 || $year > (int) date('Y'))) {
            Response::error('Choose a year.', 422);
        }
        $db = Database::tenant();
        Books::sync($db);
        [$from, $to] = self::incomeRange($year);
        $sources = [];
        foreach (self::INCOME as $key => [$label, $about]) {
            $items = self::incomeItems($db, $key, $from, $to);
            $months = [];
            foreach ($items as [$d, $c]) {
                $months[substr($d, 0, 7)] = ($months[substr($d, 0, 7)] ?? 0) + $c;
            }
            ksort($months);
            $total = array_sum(array_column($items, 1));
            $old = array_sum(array_map(fn ($i) => $i[2] ? $i[1] : 0, $items));
            $sources[] = [
                'key' => $key, 'label' => $label, 'about' => $about,
                'total' => Money::toDecimal($total),
                'old_records' => Money::toDecimal($old),
                'count' => $key === 'interest' ? null : count($items),
                'months' => array_map(fn ($m, $v) => ['month' => $m, 'value' => Money::toDecimal($v)], array_keys($months), array_values($months)),
            ];
        }
        $firstYear = (int) ($db->query("SELECT MIN(y) FROM (
            SELECT YEAR(COALESCE(paid_on, created_at)) AS y FROM membership_fees WHERE status = 'paid'
            UNION ALL SELECT YEAR(disbursed_on) FROM loans WHERE disbursed_on IS NOT NULL
            UNION ALL SELECT YEAR(received_on) FROM investment_returns) t")->fetchColumn() ?: date('Y'));
        Response::ok([
            'year' => $year,
            'years' => range((int) date('Y'), min($firstYear, (int) date('Y'))),
            'total' => Money::toDecimal(array_sum(array_map(fn ($s) => Money::fromDecimal($s['total']), $sources))),
            'sources' => $sources,
        ]);
    }

    /**
     * GET /api/reports/income/{source}?year=YYYY|all (office) - every amount behind one income card:
     * who paid it, when, and on what (the loan, the investment)
     */
    public function incomeDetail(Request $request): never
    {
        $key = $request->param('source');
        if (!isset(self::INCOME[$key])) {
            Response::error('No such income.', 404);
        }
        $year = $request->string('year') === 'all' ? null : (int) ($request->string('year') ?: date('Y'));
        $db = Database::tenant();
        Books::sync($db);
        [$from, $to] = self::incomeRange($year);
        $when = $year ? (string) $year : 'all years';
        $label = self::INCOME[$key][0];
        $text = fn ($k, $l) => ['key' => $k, 'label' => $l, 'type' => 'text'];
        $money = fn ($k, $l) => ['key' => $k, 'label' => $l, 'type' => 'money'];
        $date = fn ($k, $l) => ['key' => $k, 'label' => $l, 'type' => 'date'];
        $how = ['savings' => 'From savings', 'cash' => 'Cash', 'old_system' => 'Old records'];
        $reports = [];

        if ($key === 'membership' || $key === 'subscription') {
            $s = $db->prepare("SELECT u.full_name, u.account_no, f.year, COALESCE(f.paid_on, DATE(f.created_at)) AS paid_on, f.method, f.amount, f.user_id
                               FROM membership_fees f JOIN users u ON u.id = f.user_id
                               WHERE f.kind = ? AND f.status = 'paid' AND COALESCE(f.paid_on, DATE(f.created_at)) BETWEEN ? AND ?
                               ORDER BY paid_on DESC, u.full_name");
            $s->execute([$key, $from, $to]);
            $rows = array_map(fn ($r) => ['full_name' => $r['full_name'], 'account_no' => $r['account_no'], 'year' => (string) $r['year'], 'paid_on' => $r['paid_on'],
                'how' => $how[$r['method']] ?? $r['method'], 'amount' => $r['amount'], '_member_id' => (int) $r['user_id']], $s->fetchAll());
            $cols = [$text('full_name', 'Member'), $text('account_no', 'Account no.')];
            if ($key === 'subscription') {
                $cols[] = $text('year', 'For year');
            }
            array_push($cols, $date('paid_on', 'Paid on'), $text('how', 'How'), $money('amount', 'Amount'));
            $reports[] = ['title' => "$label, $when", 'subtitle' => count($rows) . ' payments', 'columns' => $cols, 'rows' => $rows,
                'totals' => ['amount' => Money::toDecimal(array_sum(array_map(fn ($r) => Money::fromDecimal($r['amount']), $rows)))]];
        } elseif ($key === 'processing') {
            $s = $db->prepare("SELECT u.full_name, u.account_no, l.id, l.loan_no, l.amount AS loan_amount, COALESCE(l.fee_charged_on, l.disbursed_on) AS charged_on,
                                      l.fee_from_savings, l.status, l.processing_fee, l.user_id
                               FROM loans l JOIN users u ON u.id = l.user_id
                               WHERE l.processing_fee > 0 AND COALESCE(l.fee_charged_on, l.disbursed_on) BETWEEN ? AND ?
                               ORDER BY charged_on DESC, l.id DESC");
            $s->execute([$from, $to]);
            $rows = array_map(fn ($r) => ['full_name' => $r['full_name'], 'account_no' => $r['account_no'], 'loan_no' => $r['loan_no'], 'loan_amount' => $r['loan_amount'],
                'charged_on' => $r['charged_on'], 'from' => $r['fee_from_savings'] ? 'Savings' : 'Off the cheque', 'fee' => $r['processing_fee'], '_loan_id' => (int) $r['id']], $s->fetchAll());
            $reports[] = ['title' => "$label, $when", 'subtitle' => count($rows) . ' loans', 'columns' => [
                $text('full_name', 'Member'), $text('account_no', 'Account no.'), $text('loan_no', 'Loan'), $money('loan_amount', 'Loan amount'),
                $date('charged_on', 'Taken on'), $text('from', 'Taken from'), $money('fee', 'Fee'),
            ], 'rows' => $rows, 'totals' => ['fee' => Money::toDecimal(array_sum(array_map(fn ($r) => Money::fromDecimal($r['fee']), $rows)))]];
        } elseif ($key === 'interest') {
            $items = self::incomeItems($db, 'interest', $from, $to);
            $rows = array_map(fn ($i) => ['month' => date('F Y', strtotime($i[0])), 'amount' => Money::toDecimal($i[1])], array_reverse($items));
            $reports[] = ['title' => "$label, $when", 'subtitle' => 'Month by month, as the books earn it', 'columns' => [$text('month', 'Month'), $money('amount', 'Interest earned')],
                'rows' => $rows, 'totals' => ['amount' => Money::toDecimal(array_sum(array_column($items, 1)))]];
            // Loan by loan: the interest on each loan's instalments falling due in the period
            $s = $db->prepare("SELECT l.id, l.loan_no, u.full_name, u.account_no, SUM(i.interest) AS charged, SUM(i.interest_paid) AS paid
                               FROM loan_installments i JOIN loans l ON l.id = i.loan_id JOIN users u ON u.id = l.user_id
                               WHERE i.due_date BETWEEN ? AND ? GROUP BY l.id, l.loan_no, u.full_name, u.account_no HAVING charged > 0 ORDER BY charged DESC");
            $s->execute([$from, min($to, date('Y-m-d'))]);
            $byLoan = array_map(fn ($r) => ['loan_no' => $r['loan_no'], 'full_name' => $r['full_name'], 'account_no' => $r['account_no'], 'charged' => $r['charged'], 'paid' => $r['paid'], '_loan_id' => (int) $r['id']], $s->fetchAll());
            $reports[] = ['title' => "Interest loan by loan, $when", 'subtitle' => 'Interest on each loan\'s instalments due in the period, and how much of it is paid', 'columns' => [
                $text('loan_no', 'Loan'), $text('full_name', 'Member'), $text('account_no', 'Account no.'), $money('charged', 'Interest due'), $money('paid', 'Paid so far'),
            ], 'rows' => $byLoan, 'totals' => [
                'charged' => Money::toDecimal(array_sum(array_map(fn ($r) => Money::fromDecimal($r['charged']), $byLoan))),
                'paid' => Money::toDecimal(array_sum(array_map(fn ($r) => Money::fromDecimal($r['paid']), $byLoan))),
            ]];
        } else {
            $s = $db->prepare("SELECT i.name, i.kind AS inv_kind, i.institution, r.received_on, r.kind, r.amount, r.note
                               FROM investment_returns r JOIN investments i ON i.id = r.investment_id
                               WHERE r.reversal_of IS NULL AND NOT EXISTS (SELECT 1 FROM investment_returns x WHERE x.reversal_of = r.id) AND r.received_on BETWEEN ? AND ?
                               ORDER BY r.received_on DESC");
            $s->execute([$from, $to]);
            $all = $s->fetchAll();
            $kinds = ['mutual_fund' => 'Unit trust / fund', 'fixed_deposit' => 'Fixed deposit', 'treasury' => 'Treasury bill / bond', 'shares' => 'Shares', 'property' => 'Property', 'business' => 'Business', 'other' => 'Other'];
            $byYear = [];
            foreach ($all as $r) {
                $k = $r['name'] . '|' . substr($r['received_on'], 0, 4);
                $byYear[$k] ??= ['name' => $r['name'], 'kind' => $kinds[$r['inv_kind']] ?? $r['inv_kind'], 'year' => substr($r['received_on'], 0, 4), 'amount' => 0];
                $byYear[$k]['amount'] += Money::fromDecimal((string) $r['amount']);
            }
            krsort($byYear);
            $sum = array_sum(array_map(fn ($r) => Money::fromDecimal((string) $r['amount']), $all));
            $reports[] = ['title' => "Earned on each investment, $when", 'subtitle' => 'Year by year', 'columns' => [
                $text('name', 'Investment'), $text('kind', 'Kind'), $text('year', 'Year'), $money('amount', 'Earned'),
            ], 'rows' => array_values(array_map(fn ($r) => ['amount' => Money::toDecimal($r['amount'])] + $r, $byYear)), 'totals' => ['amount' => Money::toDecimal($sum)]];
            $reports[] = ['title' => "Every return received, $when", 'subtitle' => count($all) . ' entries', 'columns' => [
                $text('name', 'Investment'), $text('institution', 'With'), $date('received_on', 'Received'), $text('what', 'What'), $text('note', 'Note'), $money('amount', 'Amount'),
            ], 'rows' => array_map(fn ($r) => ['name' => $r['name'], 'institution' => $r['institution'], 'received_on' => $r['received_on'], 'what' => $r['kind'] === 'gain' ? 'Gain on value' : 'Income', 'note' => $r['note'], 'amount' => $r['amount']], $all),
                'totals' => ['amount' => Money::toDecimal($sum)]];
        }
        Response::ok(['source' => $key, 'label' => $label, 'reports' => $reports]);
    }
}
