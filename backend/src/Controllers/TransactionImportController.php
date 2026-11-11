<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Audit;
use Sacco\Support\Ledger;
use Sacco\Support\LedgerException;
use Sacco\Support\Money;
use Sacco\Support\Notify;
use Sacco\Support\Periods;

/**
 * Dated transactions from Excel, for the super admin: deposits, withdrawals, shares and loan
 * repayments, each on its own date. Made for catching up what happened after the old system's
 * balance date, and for any batch of records kept outside the system.
 *
 * Every row is checked first, in date order with running balances (a withdrawal can't take
 * savings below zero, a repayment can't be more than the loan still owes). The upload is saved
 * whole or not at all, the same file can't go in twice, and the whole upload can be undone
 * (it is kept with the payroll uploads, so the payroll reversal undoes it).
 *
 * Rows: { row, date, account_no, name, savings, shares, loan, loan_no, reference, details }
 * Savings above zero go in, below zero come out (a withdrawal).
 */
final class TransactionImportController
{
    private const MAX_ROWS = 5000;

    /** POST /api/transaction-imports/preview { rows } */
    public function preview(Request $request): never
    {
        Response::ok($this->report($this->check($this->rows($request))));
    }

    /** POST /api/transaction-imports { rows, money_account_id?, quiet?, file_name? } */
    public function import(Request $request): never
    {
        @set_time_limit(300);
        $input = $this->rows($request);
        $moneyAccount = Periods::moneyAccount($request);
        $quiet = (bool) $request->input('quiet', true);
        $fileName = mb_substr($request->string('file_name'), 0, 190) ?: null;
        $posted = [];

        try {
            $batchId = Database::transaction(function (\PDO $db) use ($input, $moneyAccount, $quiet, $fileName, &$posted) {
                // Checked again inside the transaction, against balances nobody else can change now
                $rows = $this->check($input, $db);
                $report = $this->report($rows);
                if ($report['error_count'] > 0 || $report['problem']) {
                    Response::error($report['problem'] ?: 'Some rows have problems. Nothing was saved.', 422, ['report' => $report]);
                }
                $t = $report['totals'];
                $db->prepare(
                    "INSERT INTO payroll_batches (kind, period, file_name, file_hash, row_count, total_savings, total_withdrawn, total_shares, total_loans, money_account_id, quiet, first_date, last_date, created_by)
                     VALUES ('transactions', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([substr($report['first_date'], 0, 7), $fileName, $report['hash'], count($rows), $t['savings_in'], $t['savings_out'], $t['shares'], $t['loans'],
                    $moneyAccount, $quiet ? 1 : 0, $report['first_date'], $report['last_date'], Auth::id()]);
                $batchId = (int) $db->lastInsertId();
                $no = 'U' . str_pad((string) $batchId, 5, '0', STR_PAD_LEFT);

                Notify::$muted = $quiet;
                $payment = $db->prepare('INSERT INTO loan_payments (loan_id, amount, paid_on, reference, narration, batch_id, money_account_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($rows as $r) {
                    $meta = ['value_date' => $r['date'], 'reference' => $r['reference'] ?: "Upload $no", 'narration' => $r['details'] ?: null, 'batch_id' => $batchId, 'money_account_id' => $moneyAccount];
                    if ($r['savings'] !== 0) {
                        Ledger::post($db, $r['member_id'], 'savings', $r['savings'] > 0 ? 'deposit' : 'withdrawal', $r['savings'], $meta);
                    }
                    if ($r['shares'] > 0) {
                        Ledger::post($db, $r['member_id'], 'shares', 'deposit', $r['shares'], $meta);
                    }
                    foreach ($r['parts'] as [$loanId, $part]) {
                        $db->prepare('SELECT id FROM loans WHERE id = ? FOR UPDATE')->execute([$loanId]);
                        $payment->execute([$loanId, Money::toDecimal($part), $r['date'], $meta['reference'], $r['details'] ?: null, $batchId, $moneyAccount, Auth::id()]);
                        Loans::applyPayments($db, $loanId);
                    }
                    $posted[$r['member_id']] ??= ['in' => 0, 'out' => 0, 'shares' => 0, 'loan' => 0];
                    $p = &$posted[$r['member_id']];
                    $r['savings'] > 0 ? $p['in'] += $r['savings'] : $p['out'] -= $r['savings'];
                    $p['shares'] += $r['shares'];
                    $p['loan'] += $r['loan'];
                    unset($p);
                }
                return $batchId;
            });
        } catch (LedgerException $e) {
            Response::error('Nothing was saved: ' . $e->getMessage(), 422);
        } finally {
            Notify::$muted = false;
        }

        Audit::log('transactions.imported', 'payroll_batch', $batchId, ['rows' => count($input), 'quiet' => $quiet]);
        if (!$quiet) {
            foreach ($posted as $memberId => $p) {
                $parts = [];
                $p['in'] && $parts[] = Notify::cents($p['in']) . ' into your savings';
                $p['out'] && $parts[] = Notify::cents($p['out']) . ' out of your savings';
                $p['shares'] && $parts[] = Notify::cents($p['shares']) . ' to your shares';
                $p['loan'] && $parts[] = Notify::cents($p['loan']) . ' off your loan';
                Notify::member((int) $memberId, 'money_in', 'Entries recorded on your account', 'The SACCO office recorded: ' . implode(', ', $parts) . '.', '/statement');
            }
        }
        Response::ok(['upload' => $this->find($batchId)], 201);
    }

    /** GET /api/transaction-imports */
    public function index(Request $request): never
    {
        $ids = Database::tenant()->query("SELECT id FROM payroll_batches WHERE kind = 'transactions' ORDER BY id DESC LIMIT 100")->fetchAll(\PDO::FETCH_COLUMN);
        Response::ok(['uploads' => array_map(fn ($id) => $this->find((int) $id), $ids)]);
    }

    // ----------------------------------------------------------------------

    private function find(int $id): array
    {
        $stmt = Database::tenant()->prepare(
            "SELECT b.id, b.file_name, b.row_count, b.total_savings, b.total_withdrawn, b.total_shares, b.total_loans, b.quiet, b.first_date, b.last_date,
                    b.status, b.created_at, b.reversed_at, b.reversal_reason, c.full_name AS created_by_name, r.full_name AS reversed_by_name, a.name AS money_account
             FROM payroll_batches b LEFT JOIN users c ON c.id = b.created_by LEFT JOIN users r ON r.id = b.reversed_by LEFT JOIN gl_accounts a ON a.id = b.money_account_id
             WHERE b.id = ? AND b.kind = 'transactions'"
        );
        $stmt->execute([$id]);
        $b = $stmt->fetch() ?: Response::error('Upload not found.', 404);
        return ['id' => (int) $b['id'], 'upload_no' => 'U' . str_pad((string) $b['id'], 5, '0', STR_PAD_LEFT), 'quiet' => (bool) $b['quiet'], 'row_count' => (int) $b['row_count'],
            'money_account' => $b['money_account'] ?? 'Bank account'] + $b;
    }

    private function rows(Request $request): array
    {
        $rows = $request->input('rows', []);
        if (!is_array($rows) || !$rows) {
            Response::error('The file has no rows.', 422);
        }
        if (count($rows) > self::MAX_ROWS) {
            Response::error('A file can have at most ' . self::MAX_ROWS . ' rows. Split it by month.', 422);
        }
        return array_values($rows);
    }

    /** Every row checked, in date order, with running savings balances and amounts left on loans */
    private function check(array $input, ?\PDO $db = null): array
    {
        $db ??= Database::tenant();
        $members = [];
        foreach ($db->query("SELECT id, account_no, full_name, status FROM users WHERE role = 'member' AND account_no IS NOT NULL") as $u) {
            $members[strtolower(trim($u['account_no']))] = $u;
        }
        $loansByNo = [];
        $loansByMember = [];
        foreach ($db->query("SELECT id, loan_no, user_id FROM loans WHERE status = 'active' ORDER BY id") as $l) {
            $loansByNo[strtolower($l['loan_no'])] = $l;
            $loansByMember[(int) $l['user_id']][] = $l;
        }

        $rows = [];
        foreach ($input as $i => $raw) {
            $r = is_array($raw) ? $raw : [];
            $text = fn (string $k) => trim((string) ($r[$k] ?? ''));
            $row = ['row' => (int) ($r['row'] ?? $i + 2), 'date' => null, 'account_no' => $text('account_no'), 'name' => $text('name'), 'member_id' => null, 'member_name' => null,
                'savings' => 0, 'shares' => 0, 'loan' => 0, 'loan_id' => null, 'loan_no' => null, 'loan_ids' => [], 'parts' => [], 'reference' => mb_substr($text('reference'), 0, 80), 'details' => mb_substr($text('details'), 0, 255),
                'errors' => [], 'warnings' => []];

            $typed = $text('date');
            $date = self::date($typed);
            if ($date === null) {
                $row['errors'][] = $typed === '' ? 'The date is missing.' : "\"$typed\" isn't a date we can read (use 2026-09-30 or 30/09/2026).";
            } elseif ($date > date('Y-m-d')) {
                $row['errors'][] = 'The date is in the future.';
            } else {
                $row['date'] = $date;
                try {
                    Periods::assertOpen($date);
                } catch (LedgerException $e) {
                    $row['errors'][] = $e->getMessage();
                }
            }

            $m = $members[strtolower($row['account_no'])] ?? null;
            if (!$m) {
                $row['errors'][] = $row['account_no'] === '' ? 'The account number is missing.' : "No member has account number \"{$row['account_no']}\".";
            } elseif ($m['status'] !== 'active') {
                $row['errors'][] = "{$m['full_name']}'s account is {$m['status']}.";
            } else {
                $row['member_id'] = (int) $m['id'];
                $row['member_name'] = $m['full_name'];
                if ($row['name'] !== '' && !self::sameName($row['name'], $m['full_name'])) {
                    $row['warnings'][] = "The file says \"{$row['name']}\"; account {$row['account_no']} is {$m['full_name']}.";
                }
            }

            $amountProblem = false;
            $savings = self::signed($text('savings'));
            $savings === null ? ($row['errors'][] = 'The savings amount isn\'t a number.') && $amountProblem = true : $row['savings'] = $savings;
            $shares = self::signed($text('shares'));
            if ($shares === null) {
                $row['errors'][] = 'The shares amount isn\'t a number.';
                $amountProblem = true;
            } elseif ($shares < 0) {
                $row['errors'][] = 'Shares can\'t come out here: they leave by an internal transfer, or when a member leaves.';
                $amountProblem = true;
            } else {
                $row['shares'] = $shares;
            }
            $loan = self::signed($text('loan'));
            if ($loan === null) {
                $row['errors'][] = 'The loan repayment isn\'t a number.';
                $amountProblem = true;
            } elseif ($loan < 0) {
                $row['errors'][] = 'A loan repayment can\'t be below zero. New loans are recorded on the Loans page.';
                $amountProblem = true;
            } else {
                $row['loan'] = $loan;
            }
            if ($row['savings'] === 0 && $row['shares'] === 0 && $row['loan'] === 0 && !$amountProblem) {
                $row['errors'][] = 'The row has no amounts.';
            }

            if ($row['loan'] > 0 && $row['member_id']) {
                $wanted = strtolower($text('loan_no'));
                if ($wanted !== '') {
                    $l = $loansByNo[$wanted] ?? null;
                    if (!$l || (int) $l['user_id'] !== $row['member_id']) {
                        $row['errors'][] = "{$row['member_name']} has no running loan " . $text('loan_no') . '.';
                    } else {
                        $row['loan_id'] = (int) $l['id'];
                    }
                } else {
                    $own = $loansByMember[$row['member_id']] ?? [];
                    if (count($own) === 1) {
                        $row['loan_id'] = (int) $own[0]['id'];
                    } elseif (!$own) {
                        $row['errors'][] = "{$row['member_name']} has no running loan to repay.";
                    } else {
                        // Oldest loan first, any excess to the next (shown on the check)
                        $row['loan_ids'] = array_map(fn ($l) => (int) $l['id'], $own);
                    }
                }
                if ($row['loan_id']) {
                    $row['loan_ids'] = [$row['loan_id']];
                }
            }
            $rows[] = $row;
        }

        // In date order (file order within a day), with running balances
        usort($rows, fn ($a, $b) => [$a['date'] ?? '9999', $a['row']] <=> [$b['date'] ?? '9999', $b['row']]);
        $savings = [];
        $left = [];
        foreach ($rows as &$row) {
            if ($row['member_id'] && $row['savings'] < 0) {
                $savings[$row['member_id']] ??= Ledger::balance($db, $row['member_id'], 'savings');
                if ($savings[$row['member_id']] + $row['savings'] < 0) {
                    $row['errors'][] = 'Taking ' . number_format(-$row['savings'] / 100) . ' out would leave ' . $row['member_name'] . '\'s savings below zero (' . number_format($savings[$row['member_id']] / 100) . ' by then).';
                }
            }
            if ($row['member_id']) {
                $savings[$row['member_id']] ??= Ledger::balance($db, $row['member_id'], 'savings');
                $savings[$row['member_id']] += $row['savings'];
            }
            if ($row['loan_ids'] && $row['loan'] > 0) {
                $due = $row['loan'];
                $room = 0;
                foreach ($row['loan_ids'] as $i => $loanId) {
                    $left[$loanId] ??= Loans::position($db, $loanId)['total_left'];
                    $room += $left[$loanId];
                    // The last loan takes whatever is left of the row (so an overpayment shows up)
                    $part = $i === count($row['loan_ids']) - 1 ? $due : min($due, $left[$loanId]);
                    if ($part > 0) {
                        $row['parts'][] = [$loanId, $part];
                        $left[$loanId] -= $part;
                        $due -= $part;
                    }
                }
                $nos = [];
                foreach ($row['parts'] as [$loanId]) {
                    $nos[] = $db->query('SELECT loan_no FROM loans WHERE id = ' . (int) $loanId)->fetchColumn();
                }
                $row['loan_no'] = implode(', ', $nos);
                if ($row['loan'] > $room) {
                    $row['errors'][] = ($row['loan_id'] ? "Loan {$row['loan_no']} has" : "{$row['member_name']}'s loans have") . ' only ' . number_format($room / 100) . ' left to pay by then.';
                } elseif (count($row['loan_ids']) > 1) {
                    $row['warnings'][] = "{$row['member_name']} has " . count($row['loan_ids']) . " running loans and no Loan No: paid to the oldest first ({$row['loan_no']}).";
                }
            }
        }
        unset($row);
        return $rows;
    }

    private function report(array $rows): array
    {
        $totals = ['savings_in' => 0, 'savings_out' => 0, 'shares' => 0, 'loans' => 0];
        $dates = [];
        foreach ($rows as $r) {
            $r['savings'] > 0 ? $totals['savings_in'] += $r['savings'] : $totals['savings_out'] -= $r['savings'];
            $totals['shares'] += $r['shares'];
            $totals['loans'] += $r['loan'];
            $r['date'] && $dates[] = $r['date'];
        }
        // The same rows again (in any order) are the same upload
        $key = array_map(fn ($r) => [$r['date'], strtolower($r['account_no']), $r['savings'], $r['shares'], $r['loan'], strtolower($r['loan_no'] ?? ''), $r['reference']], $rows);
        sort($key);
        $hash = hash('sha256', json_encode($key));
        $dup = Database::tenant()->prepare("SELECT id, created_at FROM payroll_batches WHERE kind = 'transactions' AND file_hash = ? AND status = 'posted' LIMIT 1");
        $dup->execute([$hash]);
        $earlier = $dup->fetch();

        return [
            'rows' => array_map(fn ($r) => [
                'row' => $r['row'], 'date' => $r['date'], 'account_no' => $r['account_no'], 'member_name' => $r['member_name'] ?? $r['name'],
                'savings' => Money::toDecimal($r['savings']), 'shares' => Money::toDecimal($r['shares']), 'loan' => Money::toDecimal($r['loan']), 'loan_no' => $r['loan_no'],
                'reference' => $r['reference'], 'errors' => $r['errors'], 'warnings' => $r['warnings'],
            ], $rows),
            'row_count' => count($rows),
            'error_count' => count(array_filter($rows, fn ($r) => $r['errors'])),
            'warning_count' => count(array_filter($rows, fn ($r) => $r['warnings'])),
            'member_count' => count(array_unique(array_filter(array_column($rows, 'member_id')))),
            'totals' => array_map(fn ($c) => Money::toDecimal($c), $totals),
            'first_date' => $dates ? min($dates) : null,
            'last_date' => $dates ? max($dates) : null,
            'hash' => $hash,
            'problem' => $earlier ? 'This file was already brought in on ' . date('j M Y', strtotime($earlier['created_at'])) . ' (upload U' . str_pad((string) $earlier['id'], 5, '0', STR_PAD_LEFT) . ').' : '',
        ];
    }

    /** Dates as people type them: 2026-09-30, 30/09/2026 or 30-09-2026 (day first), 30 Sep 2026 */
    private static function date(string $s): ?string
    {
        foreach (['!Y-m-d', '!d/m/Y', '!j/n/Y', '!d-m-Y', '!j-n-Y', '!j M Y', '!j F Y', '!d M Y', '!d F Y'] as $format) {
            $d = \DateTime::createFromFormat($format, $s);
            $errors = \DateTime::getLastErrors();
            if ($d && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))) {
                return $d->format('Y-m-d');
            }
        }
        return null;
    }

    /** Money that may be below zero, in cents; empty is zero */
    private static function signed(string $text): ?int
    {
        $text = str_replace([',', ' '], '', $text);
        if ($text === '' || $text === '-') {
            return 0;
        }
        // Accounting style (1,000) means minus
        if (preg_match('/^\((.+)\)$/', $text, $m)) {
            $text = '-' . $m[1];
        }
        $negative = str_starts_with($text, '-');
        $cents = Money::parse($negative ? substr($text, 1) : $text);
        return $cents === null ? null : ($negative ? -$cents : $cents);
    }

    /** Names match loosely: any shared word of three letters or more */
    private static function sameName(string $a, string $b): bool
    {
        $words = fn (string $s) => array_filter(preg_split('/[^a-z]+/', strtolower($s)), fn ($w) => strlen($w) >= 3);
        return (bool) array_intersect($words($a), $words($b));
    }
}
