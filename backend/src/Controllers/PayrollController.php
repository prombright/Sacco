<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Support\Periods;
use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Notify;
use Sacco\Support\Audit;
use Sacco\Support\Ledger;
use Sacco\Support\LedgerException;
use Sacco\Support\Money;

/**
 * Monthly payroll uploads. The browser reads the Excel/CSV file and sends its rows as
 * { row, account_no, name, savings, shares, loan, loan_no } (see frontend/src/payroll.ts - the one
 * place to change when the SACCO's real payroll sheet has different columns).
 *
 * preview: checks every row, saves nothing. post: checks again and saves every row in one
 * transaction - all of it or none of it. A posted batch can be reversed as a whole.
 */
final class PayrollController
{
    private const MAX_ROWS = 2000;

    /** GET /api/payroll/members - active members to pre-fill the template, with last month's amounts */
    public function members(Request $request): never
    {
        $db = Database::tenant();
        $last = $db->query("SELECT id FROM payroll_batches WHERE status = 'posted' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0;
        $stmt = $db->prepare(
            "SELECT u.id, u.account_no, u.full_name, u.employee_no,
                    (SELECT SUM(t.amount) FROM transactions t WHERE t.batch_id = ? AND t.user_id = u.id AND t.account = 'savings' AND t.type = 'deposit') AS last_savings,
                    (SELECT SUM(t.amount) FROM transactions t WHERE t.batch_id = ? AND t.user_id = u.id AND t.account = 'shares' AND t.type = 'deposit') AS last_shares
             FROM users u WHERE u.status = 'active' AND u.role = 'member' AND u.account_no IS NOT NULL ORDER BY u.full_name"
        );
        $stmt->execute([$last, $last]);
        $members = $stmt->fetchAll();

        // The next instalment of each active loan, so the loan column comes pre-filled
        foreach ($members as &$m) {
            $loan = $db->prepare("SELECT id, loan_no FROM loans WHERE user_id = ? AND status = 'active' ORDER BY id LIMIT 1");
            $loan->execute([$m['id']]);
            $loan = $loan->fetch();
            $m['loan_no'] = $loan['loan_no'] ?? null;
            $m['loan_due'] = $loan ? Money::toDecimal(Loans::position($db, (int) $loan['id'])['next_due_amount']) : null;
            unset($m['id']);
        }
        unset($m);
        Response::ok(['members' => $members]);
    }

    /** POST /api/payroll/preview { period, rows } */
    public function preview(Request $request): never
    {
        $period = $this->period($request);
        $rows = $this->check($this->rows($request));
        Response::ok($this->report($period, $rows));
    }

    /** POST /api/payroll/batches { period, value_date?, file_name?, rows, confirm_duplicate? } */
    public function post(Request $request): never
    {
        $period = $this->period($request);
        $valueDate = $request->string('value_date') ?: date('Y-m-d');
        $d = \DateTime::createFromFormat('!Y-m-d', $valueDate);
        if (!$d || $d->format('Y-m-d') !== $valueDate || $valueDate > date('Y-m-d')) {
            Response::error('Enter a valid posting date, not in the future.', 422);
        }
        Periods::check($valueDate);
        $fileName = mb_substr($request->string('file_name'), 0, 190) ?: null;
        $input = $this->rows($request);

        $started = microtime(true);
        $posted = [];
        $batchId = Database::transaction(function (\PDO $db) use ($period, $valueDate, $fileName, $input, $request, &$posted) {
            // Checked again inside the transaction: balances may have moved since the preview
            $rows = $this->check($input);
            $posted = $rows;
            $report = $this->report($period, $rows);
            if ($report['error_count'] > 0) {
                Response::error('Some rows have problems. Fix them in the file and upload it again.', 422, ['report' => $report]);
            }
            if ($report['duplicate_period'] && !$request->input('confirm_duplicate')) {
                Response::error("A payroll for $period has already been posted.", 409, ['report' => $report]);
            }

            $db->prepare('INSERT INTO payroll_batches (period, file_name, row_count, total_savings, total_shares, total_loans, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$period, $fileName, $report['posted_rows'], $report['totals']['savings'], $report['totals']['shares'], $report['totals']['loan'], Auth::id()]);
            $batchId = (int) $db->lastInsertId();
            $meta = ['value_date' => $valueDate, 'reference' => "Payroll $period", 'batch_id' => $batchId];

            $payment = $db->prepare('INSERT INTO loan_payments (loan_id, amount, paid_on, reference, batch_id, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($rows as $r) {
                if ($r['savings'] > 0) {
                    Ledger::post($db, $r['member_id'], 'savings', 'deposit', $r['savings'], $meta);
                }
                if ($r['shares'] > 0) {
                    Ledger::post($db, $r['member_id'], 'shares', 'deposit', $r['shares'], $meta);
                }
                if ($r['loan'] > 0) {
                    $db->prepare('SELECT id FROM loans WHERE id = ? FOR UPDATE')->execute([$r['loan_id']]);
                    $payment->execute([$r['loan_id'], Money::toDecimal($r['loan']), $valueDate, "Payroll $period", $batchId, Auth::id()]);
                    Loans::applyPayments($db, $r['loan_id']);
                }
            }
            return $batchId;
        });
        Audit::log('payroll.posted', 'payroll_batch', $batchId, ['period' => $period, 'seconds' => round(microtime(true) - $started, 2)]);
        // Each member hears what came off their pay and where it went
        $month = date('F Y', strtotime("$period-01"));
        foreach ($posted as $r) {
            $parts = [];
            $r['savings'] > 0 && $parts[] = Notify::cents($r['savings']) . ' to your savings';
            $r['shares'] > 0 && $parts[] = Notify::cents($r['shares']) . ' to your shares';
            $r['loan'] > 0 && $parts[] = Notify::cents($r['loan']) . ' off your loan';
            if ($parts) {
                $total = $r['savings'] + $r['shares'] + $r['loan'];
                Notify::member($r['member_id'], 'money_in', "$month payroll: " . Notify::cents($total) . ' received',
                    'From your ' . $month . ' pay: ' . implode(', ', $parts) . '.', '/statement');
            }
        }

        Response::ok(['batch' => $this->batch($batchId)], 201);
    }

    /** GET /api/payroll/batches */
    public function index(Request $request): never
    {
        $ids = Database::tenant()->query('SELECT id FROM payroll_batches ORDER BY id DESC LIMIT 100')->fetchAll(\PDO::FETCH_COLUMN);
        Response::ok(['batches' => array_map(fn ($id) => $this->batch((int) $id), $ids)]);
    }

    /** GET /api/payroll/batches/{id} - the batch and, per member, what it posted */
    public function show(Request $request): never
    {
        $id = (int) $request->param('id');
        $batch = $this->batch($id);
        $db = Database::tenant();

        $stmt = $db->prepare(
            "SELECT u.id, u.full_name, u.account_no,
                    SUM(CASE WHEN t.account = 'savings' AND t.type = 'deposit' THEN t.amount ELSE 0 END) AS savings,
                    SUM(CASE WHEN t.account = 'shares' AND t.type = 'deposit' THEN t.amount ELSE 0 END) AS shares
             FROM transactions t JOIN users u ON u.id = t.user_id
             WHERE t.batch_id = ? GROUP BY u.id"
        );
        $stmt->execute([$id]);
        $byMember = [];
        foreach ($stmt->fetchAll() as $r) {
            $byMember[$r['id']] = ['full_name' => $r['full_name'], 'account_no' => $r['account_no'], 'savings' => $r['savings'], 'shares' => $r['shares'], 'loan' => '0.00', 'loan_no' => null];
        }
        $stmt = $db->prepare(
            'SELECT u.id, u.full_name, u.account_no, l.loan_no, p.amount
             FROM loan_payments p JOIN loans l ON l.id = p.loan_id JOIN users u ON u.id = l.user_id
             WHERE p.batch_id = ? AND p.reversal_of IS NULL'
        );
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $r) {
            $byMember[$r['id']] ??= ['full_name' => $r['full_name'], 'account_no' => $r['account_no'], 'savings' => '0.00', 'shares' => '0.00'];
            $byMember[$r['id']]['loan'] = $r['amount'];
            $byMember[$r['id']]['loan_no'] = $r['loan_no'];
        }
        usort($byMember, fn ($a, $b) => strcmp($a['full_name'], $b['full_name']));

        Response::ok(['batch' => $batch, 'rows' => array_values($byMember)]);
    }

    /** POST /api/payroll/batches/{id}/reverse { reason } - undoes every entry the upload made */
    public function reverse(Request $request): never
    {
        $id = (int) $request->param('id');
        $batch = $this->batch($id);
        if ($batch['status'] !== 'posted') {
            Response::error('This upload has already been reversed.', 422);
        }
        $reason = mb_substr($request->string('reason'), 0, 255);
        if ($reason === '') {
            Response::error('Say why this upload is being reversed.', 422, ['reason' => 'Required']);
        }

        Database::transaction(function (\PDO $db) use ($id, $reason) {
            $db->prepare('SELECT id FROM payroll_batches WHERE id = ? FOR UPDATE')->execute([$id]);
            $narration = "Payroll upload reversed: $reason";

            // Savings and shares entries (skipping any an admin already reversed one by one)
            $stmt = $db->prepare(
                "SELECT t.id, u.full_name FROM transactions t JOIN users u ON u.id = t.user_id
                 WHERE t.batch_id = ? AND t.type <> 'reversal' AND NOT EXISTS (SELECT 1 FROM transactions r WHERE r.reversal_of = t.id)"
            );
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll() as $t) {
                try {
                    Ledger::reverse($db, (int) $t['id'], $narration, $id);
                } catch (LedgerException $e) {
                    throw new LedgerException("Can't reverse {$t['full_name']}'s entry: " . $e->getMessage());
                }
            }

            // Loan repayments
            $stmt = $db->prepare(
                'SELECT p.id, p.loan_id, p.amount, p.reference FROM loan_payments p
                 WHERE p.batch_id = ? AND p.reversal_of IS NULL AND NOT EXISTS (SELECT 1 FROM loan_payments r WHERE r.reversal_of = p.id)'
            );
            $stmt->execute([$id]);
            $loans = [];
            $insert = $db->prepare('INSERT INTO loan_payments (loan_id, amount, paid_on, reference, narration, reversal_of, batch_id, created_by) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?)');
            foreach ($stmt->fetchAll() as $p) {
                $insert->execute([$p['loan_id'], Money::toDecimal(-Money::fromDecimal($p['amount'])), $p['reference'], $narration, $p['id'], $id, Auth::id()]);
                $loans[(int) $p['loan_id']] = true;
            }
            foreach (array_keys($loans) as $loanId) {
                Loans::applyPayments($db, $loanId);
            }

            $db->prepare("UPDATE payroll_batches SET status = 'reversed', reversed_by = ?, reversed_at = NOW(), reversal_reason = ? WHERE id = ?")
                ->execute([Auth::id(), $reason, $id]);
        });
        Audit::log('payroll.reversed', 'payroll_batch', $id, ['reason' => $reason]);
        $who = Database::tenant()->prepare(
            'SELECT user_id FROM transactions WHERE batch_id = ? UNION SELECT l.user_id FROM loan_payments p JOIN loans l ON l.id = p.loan_id WHERE p.batch_id = ?'
        );
        $who->execute([$id, $id]);
        $month = date('F Y', strtotime($batch['period'] . '-01'));
        foreach ($who->fetchAll(\PDO::FETCH_COLUMN) as $userId) {
            Notify::member((int) $userId, 'money_reversed', "The $month payroll entries were reversed",
                "The SACCO office reversed the $month payroll upload: $reason. Your balances are back to what they were before it.", '/statement');
        }

        Response::ok(['batch' => $this->batch($id)]);
    }

    // ----------------------------------------------------------------------

    private function period(Request $request): string
    {
        $period = $request->string('period');
        if (!preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $period)) {
            Response::error('Choose the payroll month.', 422, ['period' => 'Required']);
        }
        return $period;
    }

    private function rows(Request $request): array
    {
        $rows = $request->input('rows', []);
        if (!is_array($rows) || !$rows) {
            Response::error('The file has no rows to post.', 422);
        }
        if (count($rows) > self::MAX_ROWS) {
            Response::error('A file can have at most ' . self::MAX_ROWS . ' rows.', 422);
        }
        return array_values($rows);
    }

    /**
     * Each row with what's wrong (errors stop the upload) and what's worth a second look (warnings).
     * Amounts come back in cents.
     */
    private function check(array $input): array
    {
        $db = Database::tenant();
        $members = [];
        foreach ($db->query("SELECT id, account_no, full_name, status FROM users WHERE role = 'member' AND account_no IS NOT NULL") as $u) {
            $members[strtolower(trim($u['account_no']))] = $u;
        }
        $activeLoans = [];
        foreach ($db->query("SELECT id, loan_no, user_id FROM loans WHERE status = 'active' ORDER BY id") as $l) {
            $activeLoans[(int) $l['user_id']][] = $l;
        }

        $seen = [];
        $loanLeft = [];
        $out = [];
        foreach ($input as $i => $raw) {
            $raw = is_array($raw) ? $raw : [];
            $r = [
                'row' => (int) ($raw['row'] ?? $i + 2),
                'account_no' => trim((string) ($raw['account_no'] ?? '')),
                'name' => trim((string) ($raw['name'] ?? '')),
                'member_id' => null,
                'member_name' => null,
                'savings' => 0,
                'shares' => 0,
                'loan' => 0,
                'loan_id' => null,
                'loan_no' => null,
                'errors' => [],
                'warnings' => [],
            ];

            foreach (['savings', 'shares', 'loan'] as $field) {
                $value = $raw[$field] ?? '';
                if ($value === '' || $value === null) {
                    continue;
                }
                $cents = Money::parse($value);
                if ($cents === null || $cents < 0) {
                    $r['errors'][] = ucfirst($field) . " amount \"$value\" isn't a valid amount.";
                } else {
                    $r[$field] = $cents;
                }
            }

            $key = strtolower($r['account_no']);
            $member = $members[$key] ?? null;
            if ($r['account_no'] === '') {
                $r['errors'][] = 'No account number.';
            } elseif (!$member) {
                $r['errors'][] = "No member has account number {$r['account_no']}.";
            } elseif (isset($seen[$key])) {
                $r['errors'][] = "Account {$r['account_no']} is also on row {$seen[$key]}.";
            } else {
                $seen[$key] = $r['row'];
                $r['member_id'] = (int) $member['id'];
                $r['member_name'] = $member['full_name'];
                if ($member['status'] !== 'active') {
                    $r['warnings'][] = $member['status'] === 'left' ? 'This member has left the school.' : 'This member is suspended.';
                }
                if ($r['name'] !== '' && !self::namesMatch($r['name'], $member['full_name'])) {
                    $r['warnings'][] = "The file says \"{$r['name']}\" but this account belongs to {$member['full_name']}.";
                }
            }

            if ($r['member_id'] && $r['loan'] > 0) {
                $theirs = $activeLoans[$r['member_id']] ?? [];
                $wanted = trim((string) ($raw['loan_no'] ?? ''));
                $loan = $wanted !== ''
                    ? (array_values(array_filter($theirs, fn ($l) => strcasecmp($l['loan_no'], $wanted) === 0))[0] ?? null)
                    : ($theirs[0] ?? null);
                if (!$loan) {
                    $r['errors'][] = $wanted !== '' ? "Loan $wanted isn't an active loan of this member." : 'This member has no active loan to repay.';
                } else {
                    $loanId = (int) $loan['id'];
                    $loanLeft[$loanId] ??= Loans::position($db, $loanId)['total_left'];
                    if ($r['loan'] > $loanLeft[$loanId]) {
                        $r['errors'][] = 'Loan repayment is more than the ' . number_format($loanLeft[$loanId] / 100) . " still owed on {$loan['loan_no']}.";
                    } else {
                        $loanLeft[$loanId] -= $r['loan'];
                        $r['loan_id'] = $loanId;
                        $r['loan_no'] = $loan['loan_no'];
                    }
                }
            }

            if (!$r['errors'] && $r['savings'] + $r['shares'] + $r['loan'] === 0) {
                $r['warnings'][] = 'Nothing to post on this row.';
            }
            $out[] = $r;
        }
        return $out;
    }

    /** Words in common, ignoring case and order: "OKELLO Peter" matches "Peter Okello" */
    private static function namesMatch(string $a, string $b): bool
    {
        $words = fn (string $s) => array_filter(preg_split('/[^a-z]+/', strtolower($s)), fn ($w) => strlen($w) > 1);
        return (bool) array_intersect($words($a), $words($b));
    }

    private function report(string $period, array $rows): array
    {
        $totals = ['savings' => 0, 'shares' => 0, 'loan' => 0];
        $posted = 0;
        foreach ($rows as $r) {
            if (!$r['errors']) {
                foreach ($totals as $k => $_) {
                    $totals[$k] += $r[$k];
                }
                $posted += ($r['savings'] + $r['shares'] + $r['loan']) > 0 ? 1 : 0;
            }
        }
        $dup = Database::tenant()->prepare("SELECT COUNT(*) FROM payroll_batches WHERE period = ? AND status = 'posted'");
        $dup->execute([$period]);

        return [
            'period' => $period,
            'rows' => array_map(fn ($r) => array_merge($r, [
                'savings' => Money::toDecimal($r['savings']),
                'shares' => Money::toDecimal($r['shares']),
                'loan' => Money::toDecimal($r['loan']),
            ]), $rows),
            'row_count' => count($rows),
            'posted_rows' => $posted,
            'error_count' => count(array_filter($rows, fn ($r) => $r['errors'])),
            'warning_count' => count(array_filter($rows, fn ($r) => $r['warnings'])),
            'totals' => array_map([Money::class, 'toDecimal'], $totals),
            'duplicate_period' => (int) $dup->fetchColumn() > 0,
        ];
    }

    private function batch(int $id): array
    {
        $stmt = Database::tenant()->prepare(
            'SELECT b.*, c.full_name AS created_by_name, r.full_name AS reversed_by_name
             FROM payroll_batches b LEFT JOIN users c ON c.id = b.created_by LEFT JOIN users r ON r.id = b.reversed_by WHERE b.id = ?'
        );
        $stmt->execute([$id]);
        $b = $stmt->fetch() ?: Response::error('Upload not found.', 404);
        $b['id'] = (int) $b['id'];
        $b['row_count'] = (int) $b['row_count'];
        return $b;
    }
}
