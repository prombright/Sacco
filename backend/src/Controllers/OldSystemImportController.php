<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Audit;
use Sacco\Support\Money;
use Sacco\Support\Periods;

/**
 * Bringing a SACCO in from its old accounting system, after the members are in (see
 * MemberImportController): each borrower's loan as it stands, what members are owed as savings
 * interest, one opening journal for the rest of the books, and the old transactions for
 * reference. Every file is checked first and saved whole or not at all, and none sends a message
 * to anyone. Each can be brought in once.
 */
final class OldSystemImportController
{
    private const REFERENCE = 'Old system';
    private const JOURNAL_REFERENCE = 'Old system opening';
    private const MAX_ROWS = 5000;
    // These follow members' own records, so the journal may not touch them
    private const CONTROL = ['member_savings', 'share_capital', 'loans', 'interest_receivable', 'penalty_receivable'];

    /** POST /api/old-system/{kind}/preview { rows } */
    public function preview(Request $request): never
    {
        Response::ok($this->check($this->kind($request), $this->rows($request)));
    }

    /** POST /api/old-system/{kind} { rows } */
    public function import(Request $request): never
    {
        // A whole SACCO arrives at once: give it time on a slower server
        @set_time_limit(300);
        $kind = $this->kind($request);
        $report = $this->check($kind, $this->rows($request));
        if ($report['error_count'] > 0 || $report['problem']) {
            Response::error($report['problem'] ?: 'Some rows have problems. Nothing was saved.', 422, ['report' => $report]);
        }
        $rows = $report['rows'];
        $count = Database::transaction(fn (\PDO $db) => match ($kind) {
            'loans' => $this->saveLoans($db, $rows),
            'payable' => $this->savePayable($db, $rows),
            'journal' => $this->saveJournal($db, $rows),
        });
        Audit::log("old_system.$kind", null, null, ['count' => $count, 'total' => $report['total']]);
        Response::ok(['saved' => $count, 'total' => $report['total']], 201);
    }

    /** POST /api/old-system/history { rows, first } - sent in parts; the first part clears any earlier try */
    public function history(Request $request): never
    {
        // A whole SACCO arrives at once: give it time on a slower server
        @set_time_limit(300);
        $rows = $this->rows($request);
        $members = $this->memberIds();
        $clean = [];
        foreach ($rows as $i => $raw) {
            $r = is_array($raw) ? $raw : [];
            $date = $this->date((string) ($r['date'] ?? ''));
            $code = trim((string) ($r['account_no'] ?? ''));
            if (!$date || $code === '') {
                Response::error('Row ' . ((int) ($r['row'] ?? $i + 2)) . ' needs an account number and a date. Nothing in this part was saved.', 422);
            }
            $money = [];
            foreach (['savings', 'shares', 'loan', 'loan_interest', 'savings_interest', 'other'] as $k) {
                $text = trim((string) ($r[$k] ?? ''));
                $cents = $text === '' ? 0 : self::signed($text);
                if ($cents === null) {
                    Response::error('Row ' . ((int) ($r['row'] ?? $i + 2)) . " has an amount we can't read. Nothing in this part was saved.", 422);
                }
                $money[] = Money::toDecimal($cents);
            }
            $clean[] = [$members[strtolower($code)] ?? null, $code, $date, mb_substr(trim((string) ($r['details'] ?? '')), 0, 255) ?: null, mb_substr(trim((string) ($r['voucher'] ?? '')), 0, 60) ?: null, ...$money];
        }
        $first = (bool) $request->input('first', false);
        Database::transaction(function (\PDO $db) use ($clean, $first) {
            if ($first) {
                $db->exec('DELETE FROM old_system_history');
            }
            $insert = $db->prepare('INSERT INTO old_system_history (user_id, old_code, entry_date, details, voucher, savings, shares, loan, loan_interest, savings_interest, other) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($clean as $row) {
                $insert->execute($row);
            }
        });
        if ($first) {
            Audit::log('old_system.history', null, null, ['first_part' => count($clean)]);
        }
        Response::ok(['saved' => count($clean)], 201);
    }

    /** GET /api/me/old-history?page= */
    public function myHistory(Request $request): never
    {
        $this->showHistory($request, Auth::id());
    }

    /** GET /api/members/{id}/old-history?page= */
    public function memberHistory(Request $request): never
    {
        $this->showHistory($request, (int) $request->param('id'));
    }

    private function showHistory(Request $request, int $userId): never
    {
        $db = Database::tenant();
        $perPage = 25;
        $page = max(1, (int) $request->query('page', 1));
        $count = $db->prepare('SELECT COUNT(*) FROM old_system_history WHERE user_id = ?');
        $count->execute([$userId]);
        $rows = $db->prepare('SELECT entry_date, details, voucher, savings, shares, loan, loan_interest, savings_interest, other FROM old_system_history WHERE user_id = ? ORDER BY entry_date DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $rows->execute([$userId]);
        $payable = $db->prepare('SELECT amount, as_at FROM old_system_interest_payable WHERE user_id = ?');
        $payable->execute([$userId]);
        Response::ok(['transactions' => $rows->fetchAll(), 'total' => (int) $count->fetchColumn(), 'page' => $page, 'per_page' => $perPage, 'interest_payable' => $payable->fetch() ?: null]);
    }

    private function kind(Request $request): string
    {
        $kind = $request->param('kind');
        in_array($kind, ['loans', 'payable', 'journal'], true) || Response::error('Unknown file.', 404);
        return $kind;
    }

    private function rows(Request $request): array
    {
        $rows = $request->input('rows', []);
        if (!is_array($rows) || !$rows) {
            Response::error('The file has no rows.', 422);
        }
        if (count($rows) > self::MAX_ROWS) {
            Response::error('Too many rows at once.', 422);
        }
        return array_values($rows);
    }

    private function check(string $kind, array $input): array
    {
        [$rows, $total, $problem] = match ($kind) {
            'loans' => $this->checkLoans($input),
            'payable' => $this->checkPayable($input),
            'journal' => $this->checkJournal($input),
        };
        $bad = array_filter($rows, fn ($r) => $r['errors']);
        // Instalments from an old date look unpaid until the repayments since then are entered
        $date = $rows[0]['date'] ?? null;
        $stale = $kind === 'loans' && $date && strtotime($date) < strtotime('-35 days');
        $warning = $stale ? 'These balances are from ' . date('j M Y', strtotime($date)) . '. Instalments since then will show as late until those repayments are entered (date each payroll on its real month). A newer copy of the old system avoids this.' : '';
        if ($kind === 'loans') {
            $warning = trim($warning . ' Loan reminders are paused when these loans come in; turn them back on under Settings once the loans are up to date.');
        }
        return ['rows' => $rows, 'row_count' => count($rows), 'error_count' => count($bad), 'total' => Money::toDecimal($total), 'problem' => $problem, 'warning' => $warning];
    }

    /** @return array<string, int> lower-case account number => user id */
    private function memberIds(): array
    {
        $out = [];
        foreach (Database::tenant()->query("SELECT id, account_no FROM users WHERE role = 'member' AND account_no IS NOT NULL") as $u) {
            $out[strtolower($u['account_no'])] = (int) $u['id'];
        }
        return $out;
    }

    private function checkLoans(array $input): array
    {
        $db = Database::tenant();
        $members = $this->memberIds();
        $products = [];
        foreach ($db->query('SELECT id, name FROM loan_products') as $p) {
            $products[strtolower($p['name'])] = (int) $p['id'];
        }
        $done = (bool) $db->query("SELECT 1 FROM loans WHERE source = 'old_records' AND disbursement_ref = '" . self::REFERENCE . "' LIMIT 1")->fetchColumn();
        $rows = [];
        $total = 0;
        foreach ($input as $i => $raw) {
            $r = is_array($raw) ? $raw : [];
            $text = fn (string $k) => trim((string) ($r[$k] ?? ''));
            $errors = [];
            $userId = $members[strtolower($text('account_no'))] ?? null;
            $userId || $errors[] = 'No member has account number "' . $text('account_no') . '". Bring the members in first.';
            $productId = $products[strtolower($text('product'))] ?? null;
            $productId || $errors[] = 'No loan type called "' . $text('product') . '".';
            $amount = Money::parse($text('principal'));
            ($amount === null || $amount <= 0) && $errors[] = 'The amount owed must be more than zero.';
            $months = (int) $text('months');
            ($months < 1 || $months > 120) && $errors[] = 'Months to repay must be 1 to 120.';
            $rate = Money::parse($text('rate'));
            ($rate === null || $rate < 0 || $rate > 10_00) && $errors[] = 'Interest must be between 0% and 10% a month.';
            $date = $this->date($text('start_date'));
            $date || $errors[] = 'The date isn\'t readable.';
            $rows[] = [
                'row' => (int) ($r['row'] ?? $i + 2), 'label' => $text('full_name'), 'detail' => $text('purpose'), 'amount' => Money::toDecimal((int) $amount),
                'user_id' => $userId, 'product_id' => $productId, 'cents' => (int) $amount, 'months' => $months, 'rate' => (int) $rate, 'date' => $date, 'errors' => $errors,
            ];
            $total += (int) $amount;
        }
        return [$rows, $total, $done ? 'Loans from the old system have already been brought in.' : ''];
    }

    private function saveLoans(\PDO $db, array $rows): int
    {
        $insert = $db->prepare(
            "INSERT INTO loans (user_id, product_id, source, amount, months, interest_rate_monthly, interest_method, purpose, status, decided_by, decided_at, decision_note, disbursed_by, disbursed_on, disbursement_ref)
             VALUES (?, ?, 'old_records', ?, ?, ?, 'reducing_balance', ?, 'active', ?, NOW(), 'Brought in from the old system', ?, ?, ?)"
        );
        foreach ($rows as $r) {
            Periods::check($r['date']);
            $insert->execute([$r['user_id'], $r['product_id'], Money::toDecimal($r['cents']), $r['months'], Money::toDecimal($r['rate']), mb_substr($r['detail'], 0, 255) ?: 'From the old system', Auth::id(), Auth::id(), $r['date'], self::REFERENCE]);
            $id = (int) $db->lastInsertId();
            $db->prepare('UPDATE loans SET loan_no = ? WHERE id = ?')->execute(['L' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
            $loan = $db->query("SELECT * FROM loans WHERE id = $id")->fetch();
            // No repayments to apply, and applying them would re-grade the borrower and message them
            Loans::createSchedule($db, $loan, $r['date']);
        }
        // Instalments since the balance date look unpaid until those repayments are entered, so
        // members aren't told they're late meanwhile; the office turns reminders back on in Settings
        $db->prepare("INSERT INTO settings (`key`, value) VALUES ('loan_reminders', 'off') ON DUPLICATE KEY UPDATE value = 'off'")->execute();
        return count($rows);
    }

    private function checkPayable(array $input): array
    {
        $db = Database::tenant();
        $members = $this->memberIds();
        $done = (bool) $db->query('SELECT 1 FROM old_system_interest_payable LIMIT 1')->fetchColumn();
        $rows = [];
        $total = 0;
        $seen = [];
        foreach ($input as $i => $raw) {
            $r = is_array($raw) ? $raw : [];
            $code = strtolower(trim((string) ($r['account_no'] ?? '')));
            $errors = [];
            $userId = $members[$code] ?? null;
            $userId || $errors[] = 'No member has account number "' . $code . '". Bring the members in first.';
            isset($seen[$code]) && $errors[] = 'That member is on the file twice.';
            $seen[$code] = true;
            $amount = self::signed(trim((string) ($r['amount'] ?? '')));
            $amount === null && $errors[] = 'The amount isn\'t readable.';
            $date = $this->date(trim((string) ($r['as_at'] ?? '')));
            $date || $errors[] = 'The date isn\'t readable.';
            $rows[] = ['row' => (int) ($r['row'] ?? $i + 2), 'label' => trim((string) ($r['full_name'] ?? '')), 'detail' => $code, 'amount' => Money::toDecimal((int) $amount), 'user_id' => $userId, 'cents' => (int) $amount, 'date' => $date, 'errors' => $errors];
            $total += (int) $amount;
        }
        return [$rows, $total, $done ? 'Savings interest payable has already been brought in.' : ''];
    }

    private function savePayable(\PDO $db, array $rows): int
    {
        $insert = $db->prepare('INSERT INTO old_system_interest_payable (user_id, amount, as_at, created_by) VALUES (?, ?, ?, ?)');
        foreach ($rows as $r) {
            $insert->execute([$r['user_id'], Money::toDecimal($r['cents']), $r['date'], Auth::id()]);
        }
        return count($rows);
    }

    private function checkJournal(array $input): array
    {
        $db = Database::tenant();
        $accounts = [];
        foreach ($db->query("SELECT id, code, name, system_key, status FROM gl_accounts") as $a) {
            $accounts[$a['code']] = $a;
        }
        $find = $db->prepare('SELECT 1 FROM journal_entries e WHERE e.reference = ? AND e.reversal_of IS NULL AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.reversal_of = e.id) LIMIT 1');
        $find->execute([self::JOURNAL_REFERENCE]);
        $done = (bool) $find->fetchColumn();
        $rows = [];
        $debits = $credits = 0;
        $dates = [];
        foreach ($input as $i => $raw) {
            $r = is_array($raw) ? $raw : [];
            $code = trim((string) ($r['code'] ?? ''));
            $errors = [];
            $account = $accounts[$code] ?? null;
            $account || $errors[] = "No account has code \"$code\".";
            if ($account && in_array($account['system_key'], self::CONTROL, true)) {
                $errors[] = "{$account['name']} follows members' own accounts and can't be in this journal.";
            }
            $debit = Money::parse(trim((string) ($r['debit'] ?? '')) ?: '0');
            $credit = Money::parse(trim((string) ($r['credit'] ?? '')) ?: '0');
            if ($debit === null || $credit === null || $debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
                $errors[] = 'Each line needs either a debit or a credit.';
            }
            $date = $this->date(trim((string) ($r['date'] ?? '')));
            $date ? $dates[$date] = true : $errors[] = 'The date isn\'t readable.';
            $debits += (int) $debit;
            $credits += (int) $credit;
            $rows[] = ['row' => (int) ($r['row'] ?? $i + 2), 'label' => $account['name'] ?? $code, 'detail' => trim((string) ($r['memo'] ?? '')), 'amount' => Money::toDecimal((int) ($debit ?: -$credit)), 'account_id' => $account['id'] ?? null, 'debit' => (int) $debit, 'credit' => (int) $credit, 'date' => $date, 'errors' => $errors];
        }
        $problem = $done ? 'The opening journal has already been posted.' : '';
        if (!$problem && $debits !== $credits) {
            $problem = 'Debits (' . number_format($debits / 100, 2) . ') and credits (' . number_format($credits / 100, 2) . ') must be equal.';
        }
        if (!$problem && count($dates) > 1) {
            $problem = 'Every line must have the same date.';
        }
        return [$rows, $debits, $problem];
    }

    private function saveJournal(\PDO $db, array $rows): int
    {
        Periods::check($rows[0]['date']);
        $db->prepare("INSERT INTO journal_entries (entry_date, narration, reference, auto, source, created_by) VALUES (?, 'Opening balances brought in from the old system', ?, 0, 'manual', ?)")
            ->execute([$rows[0]['date'], self::JOURNAL_REFERENCE, Auth::id()]);
        $id = (int) $db->lastInsertId();
        $db->prepare('UPDATE journal_entries SET entry_no = ? WHERE id = ?')->execute(['JV' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
        $insert = $db->prepare('INSERT INTO journal_lines (entry_id, account_id, debit, credit, memo) VALUES (?, ?, ?, ?, ?)');
        foreach ($rows as $r) {
            $insert->execute([$id, $r['account_id'], Money::toDecimal($r['debit']), Money::toDecimal($r['credit']), mb_substr($r['detail'], 0, 255) ?: null]);
        }
        return count($rows);
    }

    /** Money that may be below zero, in cents */
    private static function signed(string $text): ?int
    {
        $negative = str_starts_with($text, '-');
        $cents = Money::parse($negative ? substr($text, 1) : $text);
        return $cents === null ? null : ($negative ? -$cents : $cents);
    }

    private function date(string $s): ?string
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $s);
        return $d && !\DateTime::getLastErrors() && $s <= date('Y-m-d') ? $d->format('Y-m-d') : null;
    }
}
