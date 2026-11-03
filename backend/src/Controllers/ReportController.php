<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Ledger;
use Sacco\Support\Money;
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
        'members' => ['Member balances', 'Savings, shares, pledges and loans for every member, as of now.', false],
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
        $users = $db->query("SELECT id, account_no, full_name, employee_no, status, joined_on FROM users WHERE role IN ('member', 'admin', 'super_admin') ORDER BY full_name")->fetchAll();
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
             WHERE l.status IN ('pending_guarantors', 'pending_approval', 'approved', 'active') ORDER BY l.id"
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

    private function journal(string $from, string $to): array
    {
        $db = Database::tenant();
        $stmt = $db->prepare(
            "SELECT t.value_date AS date, t.created_at, u.account_no, u.full_name, t.account, t.type, t.amount, t.reference, t.narration, c.full_name AS recorded_by
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
        $stmt = Database::tenant()->prepare(
            'SELECT a.created_at, u.full_name AS actor, a.action, a.subject_type, a.subject_id, a.details, a.ip
             FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id
             WHERE a.created_at >= ? AND a.created_at < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY a.id'
        );
        $stmt->execute([$from, $to]);
        $rows = array_map(fn ($r) => [
            'when' => $r['created_at'],
            'actor' => $r['actor'] ?? 'System',
            'action' => str_replace(['.', '_'], [': ', ' '], $r['action']),
            'subject' => $r['subject_type'] ? $r['subject_type'] . ' #' . $r['subject_id'] : '',
            'details' => $r['details'],
            'ip' => $r['ip'],
        ], $stmt->fetchAll());

        return $this->finish([
            ['when', 'When', 'text'],
            ['actor', 'Who', 'text'],
            ['action', 'What', 'text'],
            ['subject', 'On', 'text'],
            ['details', 'Details', 'text'],
            ['ip', 'IP address', 'text'],
        ], $rows, totals: false);
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
}
