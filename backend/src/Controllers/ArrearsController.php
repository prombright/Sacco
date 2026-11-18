<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use PDO;
use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Audit;
use Sacco\Support\Money;
use Sacco\Support\Notify;
use Sacco\Support\Settings;

/**
 * Arrears follow-up: every late loan with what the office has done about it (notes, promises to
 * pay, reminders, guarantors told), and the actions to follow it up. Guarantors can also be told
 * automatically after a number of days late (Setup; off until the committee sets it).
 */
final class ArrearsController
{
    /** GET /api/arrears (office) */
    public function index(Request $request): never
    {
        $db = Database::tenant();
        $today = date('Y-m-d');
        $rows = $db->query(
            "SELECT l.id, l.loan_no, l.user_id, l.amount, u.full_name, u.phone, u.account_no, COALESCE(p.name, 'Loan') AS product,
                    (SELECT MIN(i.due_date) FROM loan_installments i WHERE i.loan_id = l.id AND i.due_date < CURDATE() AND " . Loans::LATE . ") AS oldest_due
             FROM loans l JOIN users u ON u.id = l.user_id LEFT JOIN loan_products p ON p.id = l.product_id
             WHERE l.status = 'active'
             HAVING oldest_due IS NOT NULL ORDER BY oldest_due, l.id"
        )->fetchAll();
        $guarantors = $db->prepare(
            "SELECT u.full_name, u.phone, g.amount FROM loan_guarantors g JOIN users u ON u.id = g.guarantor_id
             WHERE g.loan_id = ? AND g.status = 'accepted' ORDER BY u.full_name"
        );
        $history = $db->prepare(
            'SELECT f.kind, f.note, f.promise_date, f.created_at, CASE WHEN u.role = \'super_admin\' AND u.office_via_member = 0 THEN \'System administrator\' ELSE u.full_name END AS by_name FROM loan_followups f
             LEFT JOIN users u ON u.id = f.created_by WHERE f.loan_id = ? ORDER BY f.id DESC LIMIT 5'
        );
        $loans = [];
        $overdueTotal = 0;
        foreach ($rows as $r) {
            $p = Loans::position($db, (int) $r['id']);
            $overdueTotal += $p['overdue'];
            $guarantors->execute([$r['id']]);
            $history->execute([$r['id']]);
            $notes = $history->fetchAll();
            $promise = null;
            foreach ($notes as $n) {
                if ($n['kind'] === 'promise' && $n['promise_date']) {
                    $promise = $n['promise_date'];
                    break;
                }
            }
            $loans[] = [
                'id' => (int) $r['id'],
                'loan_no' => $r['loan_no'],
                'user_id' => (int) $r['user_id'],
                'borrower' => $r['full_name'],
                'account_no' => $r['account_no'],
                'phone' => $r['phone'],
                'product' => $r['product'],
                'days' => (int) (new \DateTimeImmutable($r['oldest_due']))->diff(new \DateTimeImmutable($today))->days,
                'overdue' => Money::toDecimal($p['overdue']),
                'total_left' => Money::toDecimal($p['total_left']),
                'guarantors' => $guarantors->fetchAll(),
                'history' => $notes,
                'promise' => $promise,
            ];
        }
        $lastPayroll = $db->query("SELECT MAX(period) FROM payroll_batches WHERE kind = 'payroll' AND status = 'posted'")->fetchColumn() ?: null;
        Response::ok([
            'loans' => $loans,
            'overdue_total' => Money::toDecimal($overdueTotal),
            'last_payroll' => $lastPayroll,
            'payroll_behind' => self::payrollBehind($db),
            'warn_after_days' => (int) Settings::get('guarantor_warning_days', '0'),
        ]);
    }

    /** POST /api/loans/{id}/followups { kind: note|promise, note, promise_date? } (office, loans) */
    public function note(Request $request): never
    {
        $loan = $this->loan($request);
        $kind = $request->string('kind') === 'promise' ? 'promise' : 'note';
        $note = mb_substr($request->string('note'), 0, 500);
        $promise = null;
        if ($kind === 'promise') {
            $promise = $request->string('promise_date');
            $d = \DateTime::createFromFormat('!Y-m-d', $promise);
            if (!$d || $d->format('Y-m-d') !== $promise || $promise < date('Y-m-d')) {
                Response::error('Enter the date they promised to pay by (today or later).', 422, ['promise_date' => 'Invalid']);
            }
        } elseif ($note === '') {
            Response::error('Write what happened, e.g. "Called; salary was delayed".', 422, ['note' => 'Required']);
        }
        self::record(Database::tenant(), (int) $loan['id'], $kind, $note ?: null, $promise, Auth::id());
        Audit::log('loan.followup', 'loan', (int) $loan['id'], ['kind' => $kind, 'note' => $note, 'promise_date' => $promise]);
        Response::ok();
    }

    /** POST /api/loans/{id}/remind (office, loans) - a message to the borrower about what is late */
    public function remind(Request $request): never
    {
        $loan = $this->loan($request);
        $db = Database::tenant();
        $p = Loans::position($db, (int) $loan['id']);
        if ($p['overdue'] <= 0) {
            Response::error('Nothing is late on this loan.', 422);
        }
        Notify::member((int) $loan['user_id'], 'loan_late', 'Your loan repayment is late',
            'Loan ' . $loan['loan_no'] . ' has ' . Notify::cents($p['overdue']) . ' unpaid past its due date. '
            . 'If it isn\'t coming off your salary, please pay it at the SACCO office, or talk to the office about it.', '/loans/' . $loan['id']);
        self::record($db, (int) $loan['id'], 'reminder', 'Reminder sent: ' . Notify::cents($p['overdue']) . ' late', null, Auth::id());
        Audit::log('loan.reminded', 'loan', (int) $loan['id'], ['overdue' => Money::toDecimal($p['overdue'])]);
        Response::ok();
    }

    /** POST /api/loans/{id}/warn-guarantors (office, loans) */
    public function warnGuarantors(Request $request): never
    {
        $loan = $this->loan($request);
        $sent = self::warn(Database::tenant(), $loan, Auth::id());
        if (!$sent) {
            Response::error('This loan has no guarantors, or nothing is late on it.', 422);
        }
        Audit::log('loan.guarantors_warned', 'loan', (int) $loan['id'], ['guarantors' => $sent]);
        Response::ok(['told' => $sent]);
    }

    /**
     * The daily run: guarantors of loans late by the set number of days are told, at most once a
     * month per loan. Never while the payroll is behind: then every loan looks late.
     */
    public static function runIfDue(PDO $db): int
    {
        $days = (int) Settings::get('guarantor_warning_days', '0');
        if ($days <= 0 || self::payrollBehind($db)) {
            return 0;
        }
        $loans = $db->prepare(
            "SELECT l.* FROM loans l WHERE l.status = 'active'
               AND EXISTS (SELECT 1 FROM loan_installments i WHERE i.loan_id = l.id AND i.due_date <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                           AND " . Loans::LATE . ")
               AND NOT EXISTS (SELECT 1 FROM loan_followups f WHERE f.loan_id = l.id AND f.kind = 'guarantors' AND f.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY))"
        );
        $loans->execute([$days]);
        $n = 0;
        foreach ($loans->fetchAll() as $loan) {
            if (self::warn($db, $loan, null)) {
                $n++;
            }
        }
        return $n;
    }

    /** Tells each guarantor of the loan that it is late; returns how many were told */
    private static function warn(PDO $db, array $loan, ?int $by): int
    {
        $p = Loans::position($db, (int) $loan['id']);
        if ($p['overdue'] <= 0) {
            return 0;
        }
        $g = $db->prepare("SELECT g.guarantor_id, g.amount FROM loan_guarantors g WHERE g.loan_id = ? AND g.status = 'accepted'");
        $g->execute([$loan['id']]);
        $guarantors = $g->fetchAll();
        if (!$guarantors) {
            return 0;
        }
        $who = $db->prepare('SELECT full_name FROM users WHERE id = ?');
        $who->execute([$loan['user_id']]);
        $borrower = (string) $who->fetchColumn();
        foreach ($guarantors as $x) {
            Notify::member((int) $x['guarantor_id'], 'guarantee_late', "A loan you guarantee is behind",
                "$borrower's loan {$loan['loan_no']}, which you guarantee for " . Notify::money($x['amount']) . ', has ' . Notify::cents($p['overdue'])
                . ' unpaid past its due date. Please encourage them to catch up, or talk to the SACCO office.', '/loans');
        }
        self::record($db, (int) $loan['id'], 'guarantors', count($guarantors) . ' guarantor' . (count($guarantors) === 1 ? '' : 's') . ' told: ' . Notify::cents($p['overdue']) . ' late' . ($by === null ? ' (automatically)' : ''), null, $by);
        return count($guarantors);
    }

    private static function record(PDO $db, int $loanId, string $kind, ?string $note, ?string $promise, ?int $by): void
    {
        $db->prepare('INSERT INTO loan_followups (loan_id, kind, note, promise_date, created_by) VALUES (?, ?, ?, ?, ?)')->execute([$loanId, $kind, $note, $promise, $by]);
    }

    /** The last payroll is more than a month old: repayments from salaries aren't in */
    private static function payrollBehind(PDO $db): bool
    {
        $last = $db->query("SELECT MAX(period) FROM payroll_batches WHERE kind = 'payroll' AND status = 'posted'")->fetchColumn() ?: null;
        return !$last || $last < date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
    }

    /** An active loan the office may follow up (never its own) */
    private function loan(Request $request): array
    {
        $stmt = Database::tenant()->prepare("SELECT * FROM loans WHERE id = ? AND status = 'active'");
        $stmt->execute([(int) $request->param('id')]);
        $loan = $stmt->fetch() ?: Response::error('That loan isn\'t running.', 404);
        if (Auth::isMe((int) $loan['user_id'])) {
            Response::error('This is your own loan. Another admin follows it up.', 403);
        }
        return $loan;
    }
}
