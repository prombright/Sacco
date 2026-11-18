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
        $last = $db->query("SELECT id FROM payroll_batches WHERE kind = 'payroll' AND status = 'posted' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0;
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
            $loans = $db->prepare("SELECT id, loan_no FROM loans WHERE user_id = ? AND status = 'active' ORDER BY id");
            $loans->execute([$m['id']]);
            $loans = $loans->fetchAll();
            // One loan: its number; several: none, and the upload shares the amount across them
            $m['loan_no'] = count($loans) === 1 ? $loans[0]['loan_no'] : null;
            $due = $principal = $interest = 0;
            foreach ($loans as $loan) {
                $due += Loans::position($db, (int) $loan['id'])['next_due_amount'];
                // The next instalment split, for the Loan Principal and Loan Interest columns
                $next = $db->query('SELECT principal - principal_paid AS p, interest - interest_paid AS i FROM loan_installments WHERE loan_id = ' . (int) $loan['id'] . ' AND cleared_on IS NULL ORDER BY number LIMIT 1')->fetch();
                if ($next) {
                    $principal += Money::fromDecimal((string) $next['p']);
                    $interest += Money::fromDecimal((string) $next['i']);
                }
            }
            $m['loan_due'] = $loans ? Money::toDecimal($due) : null;
            $m['loan_principal_due'] = $loans ? Money::toDecimal($principal) : null;
            $m['loan_interest_due'] = $loans ? Money::toDecimal($interest) : null;
            unset($m['id']);
        }
        unset($m);
        Response::ok(['members' => $members]);
    }

    /** POST /api/payroll/preview { period, rows } */
    public function preview(Request $request): never
    {
        $period = $this->period($request);
        $rows = $this->check($this->rows($request), $period);
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
        // Bank by default; Funds in transit while the employer hasn't paid the deductions in yet
        $moneyAccount = Periods::moneyAccount($request);
        // Catching up old months: members aren't messaged
        $quiet = (bool) $request->input('quiet', false);
        @set_time_limit(300);

        $started = microtime(true);
        $posted = [];
        Notify::$muted = $quiet;
        try {
        $batchId = Database::transaction(function (\PDO $db) use ($period, $valueDate, $fileName, $input, $request, $moneyAccount, $quiet, &$posted) {
            // Checked again inside the transaction: balances may have moved since the preview
            $rows = $this->check($input, $period);
            $posted = $rows;
            $report = $this->report($period, $rows);
            if ($report['error_count'] > 0) {
                Response::error('Some rows have problems. Fix them in the file and upload it again.', 422, ['report' => $report]);
            }
            if ($report['duplicate_period'] && !$request->input('confirm_duplicate')) {
                Response::error("A payroll for $period has already been posted.", 409, ['report' => $report]);
            }

            $db->prepare('INSERT INTO payroll_batches (period, file_name, row_count, total_savings, total_shares, total_loans, money_account_id, quiet, first_date, last_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$period, $fileName, $report['posted_rows'], $report['totals']['savings'], $report['totals']['shares'], $report['totals']['loan'], $moneyAccount, $quiet ? 1 : 0, $valueDate, $valueDate, Auth::id()]);
            $batchId = (int) $db->lastInsertId();
            $meta = ['value_date' => $valueDate, 'reference' => "Payroll $period", 'batch_id' => $batchId, 'money_account_id' => $moneyAccount];

            $payment = $db->prepare('INSERT INTO loan_payments (loan_id, amount, paid_on, reference, batch_id, money_account_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($rows as $r) {
                if ($r['savings'] > 0) {
                    Ledger::post($db, $r['member_id'], 'savings', 'deposit', $r['savings'], $meta);
                }
                if ($r['shares'] > 0) {
                    Ledger::post($db, $r['member_id'], 'shares', 'deposit', $r['shares'], $meta);
                }
                if ($r['loan'] > 0) {
                    foreach ($r['split'] ?? [['loan_id' => $r['loan_id'], 'cents' => $r['loan']]] as $part) {
                        $db->prepare('SELECT id FROM loans WHERE id = ? FOR UPDATE')->execute([$part['loan_id']]);
                        $payment->execute([$part['loan_id'], Money::toDecimal($part['cents']), $valueDate, "Payroll $period", $batchId, $moneyAccount, Auth::id()]);
                        Loans::applyPayments($db, $part['loan_id']);
                    }
                }
            }
            return $batchId;
        });
        } finally {
            Notify::$muted = false;
        }
        Audit::log('payroll.posted', 'payroll_batch', $batchId, ['period' => $period, 'quiet' => $quiet, 'seconds' => round(microtime(true) - $started, 2)]);
        // Each member hears what came off their pay and where it went
        $month = date('F Y', strtotime("$period-01"));
        foreach ($quiet ? [] : $posted as $r) {
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
        $ids = Database::tenant()->query("SELECT id FROM payroll_batches WHERE kind = 'payroll' ORDER BY id DESC LIMIT 100")->fetchAll(\PDO::FETCH_COLUMN);
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
            $byMember[$r['id']] ??= ['full_name' => $r['full_name'], 'account_no' => $r['account_no'], 'savings' => '0.00', 'shares' => '0.00', 'loan' => '0.00', 'loan_no' => null];
            $byMember[$r['id']]['loan'] = Money::toDecimal(Money::fromDecimal($byMember[$r['id']]['loan'] ?? '0') + Money::fromDecimal($r['amount']));
            $byMember[$r['id']]['loan_no'] = trim(($byMember[$r['id']]['loan_no'] ? $byMember[$r['id']]['loan_no'] . ', ' : '') . $r['loan_no']);
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

        $quiet = (bool) ($batch['quiet'] ?? false);
        $label = ($batch['kind'] ?? 'payroll') === 'transactions' ? 'Upload U' . str_pad((string) $id, 5, '0', STR_PAD_LEFT) : 'Payroll upload';
        Notify::$muted = $quiet;
        try {
        Database::transaction(function (\PDO $db) use ($id, $reason, $label) {
            $db->prepare('SELECT id FROM payroll_batches WHERE id = ? FOR UPDATE')->execute([$id]);
            $narration = "$label reversed: $reason";

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
        } finally {
            Notify::$muted = false;
        }
        Audit::log('payroll.reversed', 'payroll_batch', $id, ['reason' => $reason]);
        if ($quiet) {
            Response::ok(['batch' => $this->batch($id)]);
        }
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
    private function check(array $input, string $period): array
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
                'principal' => 0,
                'interest' => 0,
                'total' => 0,
                'loan_id' => null,
                'loan_no' => null,
                'errors' => [],
                'warnings' => [],
            ];

            $given = [];
            foreach (['savings', 'shares', 'loan', 'principal', 'interest', 'total'] as $field) {
                $value = $raw[$field] ?? '';
                if ($value === '' || $value === null) {
                    continue;
                }
                $cents = Money::parse($value);
                if ($cents === null || $cents < 0) {
                    $r['errors'][] = ucfirst($field) . " amount \"$value\" isn't a valid amount.";
                } else {
                    $r[$field] = $cents;
                    $given[$field] = true;
                }
            }
            // The loan as principal and interest; a single Loan Repayment column still works
            if (isset($given['principal']) || isset($given['interest'])) {
                $split = $r['principal'] + $r['interest'];
                if (isset($given['loan']) && $r['loan'] !== $split) {
                    $r['errors'][] = 'Loan repayment ' . number_format($r['loan'] / 100) . ' is not principal plus interest (' . number_format($split / 100) . ').';
                }
                $r['loan'] = $split;
            }
            $sum = $r['savings'] + $r['shares'] + $r['loan'];
            if (isset($given['total']) && $r['total'] !== $sum) {
                $r['errors'][] = 'Total deducted ' . number_format($r['total'] / 100) . ' but loan, savings and shares add up to ' . number_format($sum / 100) . '.';
            }
            $r['total'] = $sum;

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

            if ($r['member_id'] && $r['loan'] > 0 && trim((string) ($raw['loan_no'] ?? '')) === '' && count($activeLoans[$r['member_id']] ?? []) > 1) {
                // Several running loans and no loan number: each loan, oldest first, takes what it
                // needs this month (instalments due and arrears), anything over goes to the oldest
                $parts = [];
                $left = $r['loan'];
                $owing = [];
                foreach ($activeLoans[$r['member_id']] as $l) {
                    $lid = (int) $l['id'];
                    $loanLeft[$lid] ??= Loans::position($db, $lid)['total_left'];
                    $need = $db->prepare('SELECT COALESCE(SUM(principal + interest - principal_paid - interest_paid), 0) FROM loan_installments WHERE loan_id = ? AND due_date <= LAST_DAY(?)');
                    $need->execute([$lid, "$period-01"]);
                    $owing[$lid] = [$l, min(Money::fromDecimal((string) $need->fetchColumn()), $loanLeft[$lid])];
                }
                foreach ($owing as $lid => [$l, $need]) {
                    $take = min($left, $need);
                    if ($take > 0) {
                        $parts[$lid] = ['loan_id' => $lid, 'loan_no' => $l['loan_no'], 'cents' => $take];
                        $left -= $take;
                    }
                }
                foreach ($owing as $lid => [$l]) {
                    $room = $loanLeft[$lid] - ($parts[$lid]['cents'] ?? 0);
                    $take = min($left, $room);
                    if ($take > 0) {
                        $parts[$lid] ??= ['loan_id' => $lid, 'loan_no' => $l['loan_no'], 'cents' => 0];
                        $parts[$lid]['cents'] += $take;
                        $left -= $take;
                    }
                }
                if ($left > 0) {
                    $r['errors'][] = 'Loan repayment is ' . number_format($left / 100) . ' more than this member owes on all their loans.';
                } else {
                    foreach ($parts as $part) {
                        $loanLeft[$part['loan_id']] -= $part['cents'];
                    }
                    $r['split'] = array_values($parts);
                    $r['loan_id'] = $r['split'][0]['loan_id'];
                    $r['loan_no'] = implode(', ', array_map(fn ($x) => $x['loan_no'] . ' ' . number_format($x['cents'] / 100), $r['split']));
                    $r['warnings'][] = 'Shared across their loans: ' . $r['loan_no'] . '.';
                }
            } elseif ($r['member_id'] && $r['loan'] > 0) {
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
                        // Interest due by the end of the month is taken first; say so if the sheet splits it differently
                        if (isset($given['interest'])) {
                            $due = $db->prepare('SELECT COALESCE(SUM(interest - interest_paid), 0) FROM loan_installments WHERE loan_id = ? AND due_date <= LAST_DAY(?)');
                            $due->execute([$loanId, "$period-01"]);
                            $interestDue = Money::fromDecimal($due->fetchColumn());
                            if ($interestDue !== $r['interest']) {
                                $r['warnings'][] = 'The sheet puts ' . number_format($r['interest'] / 100) . ' to interest, but ' . number_format(min($interestDue, $r['loan']) / 100) . ' interest is due, so that goes to interest and the rest to principal.';
                            }
                        }
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
        $totals = ['savings' => 0, 'shares' => 0, 'loan' => 0, 'principal' => 0, 'interest' => 0, 'total' => 0];
        $posted = 0;
        foreach ($rows as $r) {
            if (!$r['errors']) {
                foreach ($totals as $k => $_) {
                    $totals[$k] += $r[$k];
                }
                $posted += ($r['savings'] + $r['shares'] + $r['loan']) > 0 ? 1 : 0;
            }
        }
        $dup = Database::tenant()->prepare("SELECT COUNT(*) FROM payroll_batches WHERE kind = 'payroll' AND period = ? AND status = 'posted'");
        $dup->execute([$period]);

        return [
            'period' => $period,
            'rows' => array_map(fn ($r) => array_merge($r, [
                'savings' => Money::toDecimal($r['savings']),
                'shares' => Money::toDecimal($r['shares']),
                'loan' => Money::toDecimal($r['loan']),
                'principal' => Money::toDecimal($r['principal']),
                'interest' => Money::toDecimal($r['interest']),
                'total' => Money::toDecimal($r['total']),
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
            'SELECT b.*, CASE WHEN c.role = \'super_admin\' AND c.office_via_member = 0 THEN \'System administrator\' ELSE c.full_name END AS created_by_name, CASE WHEN r.role = \'super_admin\' AND r.office_via_member = 0 THEN \'System administrator\' ELSE r.full_name END AS reversed_by_name
             FROM payroll_batches b LEFT JOIN users c ON c.id = b.created_by LEFT JOIN users r ON r.id = b.reversed_by WHERE b.id = ?'
        );
        $stmt->execute([$id]);
        $b = $stmt->fetch() ?: Response::error('Upload not found.', 404);
        $b['id'] = (int) $b['id'];
        $b['row_count'] = (int) $b['row_count'];
        return $b;
    }

    /**
     * What each member's loans ask of the payroll in a month: every instalment falling due in it,
     * across all their running loans, and what was already late before it began.
     *
     * @return array<int, array{due: int, arrears: int, loans: string[]}> by member id
     */
    private static function loanDues(\PDO $db, string $period): array
    {
        $start = "$period-01";
        $end = date('Y-m-t', strtotime($start));
        $stmt = $db->prepare(
            "SELECT l.user_id, l.loan_no,
                    COALESCE(SUM(CASE WHEN i.due_date BETWEEN ? AND ? THEN COALESCE(i.demanded, i.principal + i.interest) END), 0) AS due,
                    COALESCE(SUM(CASE WHEN i.due_date < ? THEN i.principal + i.interest - i.principal_paid - i.interest_paid END), 0) AS arrears
             FROM loans l JOIN loan_installments i ON i.loan_id = l.id
             WHERE l.status = 'active' OR (l.status = 'closed' AND l.closed_on >= ?)
             GROUP BY l.id, l.user_id, l.loan_no"
        );
        $stmt->execute([$start, $end, $start, $start]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $due = Money::fromDecimal((string) $r['due']);
            $arrears = max(0, Money::fromDecimal((string) $r['arrears']));
            if (!$due && !$arrears) {
                continue;
            }
            $m = &$out[(int) $r['user_id']];
            $m ??= ['due' => 0, 'arrears' => 0, 'loans' => []];
            $m['due'] += $due;
            $m['arrears'] += $arrears;
            $m['loans'][] = $r['loan_no'];
            unset($m);
        }
        return $out;
    }

    /**
     * GET /api/payroll/schedule?period=YYYY-MM (office) - the deduction schedule for the bursar: each
     * active member's savings and shares (as in the last payroll), every loan instalment due that
     * month across all their loans, what was late before it, and the total to deduct.
     */
    public function schedule(Request $request): never
    {
        $period = $request->string('period') ?: date('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            Response::error('Choose a month.', 422);
        }
        $db = Database::tenant();
        $last = $db->query("SELECT id FROM payroll_batches WHERE kind = 'payroll' AND status = 'posted' ORDER BY period DESC, id DESC LIMIT 1")->fetchColumn() ?: 0;
        $members = $db->prepare(
            "SELECT u.id, u.full_name, u.account_no, u.employee_no,
                    COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.batch_id = ? AND t.user_id = u.id AND t.account = 'savings' AND t.type = 'deposit'), 0) AS savings,
                    COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.batch_id = ? AND t.user_id = u.id AND t.account = 'shares' AND t.type = 'deposit'), 0) AS shares
             FROM users u WHERE u.status = 'active' AND u.role = 'member' ORDER BY u.full_name"
        );
        $members->execute([$last, $last]);
        $dues = self::loanDues($db, $period);
        $rows = [];
        $sum = ['savings' => 0, 'shares' => 0, 'loan_due' => 0, 'arrears' => 0, 'total' => 0];
        foreach ($members->fetchAll() as $m) {
            $savings = Money::fromDecimal((string) $m['savings']);
            $shares = Money::fromDecimal((string) $m['shares']);
            $d = $dues[(int) $m['id']] ?? ['due' => 0, 'arrears' => 0, 'loans' => []];
            $total = $savings + $shares + $d['due'] + $d['arrears'];
            if (!$total) {
                continue;
            }
            foreach (['savings' => $savings, 'shares' => $shares, 'loan_due' => $d['due'], 'arrears' => $d['arrears'], 'total' => $total] as $k => $v) {
                $sum[$k] += $v;
            }
            $rows[] = [
                'full_name' => $m['full_name'], 'account_no' => $m['account_no'], 'employee_no' => $m['employee_no'],
                'savings' => Money::toDecimal($savings), 'shares' => Money::toDecimal($shares),
                'loans' => implode(', ', $d['loans']), 'loan_due' => Money::toDecimal($d['due']), 'arrears' => Money::toDecimal($d['arrears']),
                'total' => Money::toDecimal($total),
            ];
        }
        Response::ok([
            'period' => $period,
            'based_on' => $last ? $db->query('SELECT period FROM payroll_batches WHERE id = ' . (int) $last)->fetchColumn() : null,
            'rows' => $rows,
            'totals' => array_map([Money::class, 'toDecimal'], $sum),
        ]);
    }

    /**
     * GET /api/payroll/batches/{id}/shortfalls (office) - after a month's payroll: every member whose
     * loan deduction came in under what fell due that month (or who wasn't on the payroll at all).
     */
    public function shortfalls(Request $request): never
    {
        $batch = $this->batch((int) $request->param('id'));
        $db = Database::tenant();
        $dues = self::loanDues($db, $batch['period']);
        $paid = $db->prepare(
            'SELECT l.user_id, SUM(p.amount) FROM loan_payments p JOIN loans l ON l.id = p.loan_id
             WHERE p.batch_id = ? AND p.reversal_of IS NULL AND NOT EXISTS (SELECT 1 FROM loan_payments r WHERE r.reversal_of = p.id)
             GROUP BY l.user_id'
        );
        $paid->execute([$batch['id']]);
        $paid = array_map(fn ($v) => Money::fromDecimal((string) $v), $paid->fetchAll(\PDO::FETCH_KEY_PAIR));
        $names = $db->query("SELECT id, full_name, account_no, employee_no FROM users WHERE role = 'member'")->fetchAll(\PDO::FETCH_UNIQUE);
        $rows = [];
        $short = 0;
        foreach ($dues as $uid => $d) {
            if (!$d['due']) {
                continue;
            }
            $got = $paid[$uid] ?? 0;
            if ($got >= $d['due']) {
                continue;
            }
            $short += $d['due'] - $got;
            $rows[] = [
                'full_name' => $names[$uid]['full_name'] ?? '?', 'account_no' => $names[$uid]['account_no'] ?? null, 'employee_no' => $names[$uid]['employee_no'] ?? null,
                'loans' => implode(', ', $d['loans']),
                'due' => Money::toDecimal($d['due']), 'deducted' => Money::toDecimal($got), 'short' => Money::toDecimal($d['due'] - $got),
                'missing' => !isset($paid[$uid]),
            ];
        }
        usort($rows, fn ($a, $b) => Money::fromDecimal($b['short']) <=> Money::fromDecimal($a['short']));
        Response::ok(['period' => $batch['period'], 'rows' => $rows, 'short_total' => Money::toDecimal($short)]);
    }
}
