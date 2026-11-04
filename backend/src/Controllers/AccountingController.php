<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Support\Books;
use Sacco\Support\Money;

/**
 * The books: chart of accounts, journal entries, and the statements worked out from them
 * (trial balance, balance sheet, income statement, account ledger, monthly loan interest).
 *
 * Statements come back in the same shape as the other reports - { title, subtitle, columns, rows,
 * totals } - so the browser shows, prints and downloads them the same way. A row's `_kind`
 * (heading, subtotal, total) marks the lines of a statement that aren't accounts.
 */
final class AccountingController
{
    /**
     * Accounts that stand for members' own balances. Only the member records move them (deposits,
     * loans, transfers...), so the books always agree with every member's account.
     */
    private const CONTROL = ['member_savings', 'share_capital', 'loans', 'interest_receivable', 'penalty_receivable'];

    public const REPORTS = [
        'trial_balance' => ['Trial balance', 'as_of'],
        'balance_sheet' => ['Balance sheet', 'as_of'],
        'income_statement' => ['Income statement', 'range'],
        'account_ledger' => ['Account ledger', 'range'],
        'loan_interest' => ['Monthly loan interest', 'month'],
    ];

    private const TYPES = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Members\' equity', 'income' => 'Income', 'expense' => 'Expenses'];

    // --- Chart of accounts ---------------------------------------------------------------------

    /** GET /api/accounting/accounts - every account with its balance today */
    public function accounts(Request $request): never
    {
        $db = Database::tenant();
        Books::sync($db);
        $totals = Books::totals($db, date('Y-m-d'));
        $used = $db->query('SELECT DISTINCT account_id FROM journal_lines')->fetchAll(\PDO::FETCH_COLUMN);
        Response::ok(['accounts' => array_map(fn ($a) => [
            'id' => (int) $a['id'],
            'code' => $a['code'],
            'name' => $a['name'],
            'type' => $a['type'],
            'system_key' => $a['system_key'],
            'description' => $a['description'],
            'status' => $a['status'],
            'balance' => Money::toDecimal(Books::balance($a, $totals)),
            'used' => in_array($a['id'], $used),
            'control' => in_array($a['system_key'], self::CONTROL, true),
        ], Books::chart($db)), 'types' => self::TYPES]);
    }

    /** POST /api/accounting/accounts { code, name, type, description } (super admin) */
    public function createAccount(Request $request): never
    {
        $d = $this->accountInput($request, null);
        $db = Database::tenant();
        $db->prepare('INSERT INTO gl_accounts (code, name, type, description) VALUES (?, ?, ?, ?)')->execute([$d['code'], $d['name'], $d['type'], $d['description']]);
        $id = (int) $db->lastInsertId();
        Audit::log('account.created', 'gl_account', $id, ['name' => "{$d['code']} {$d['name']}", 'type' => $d['type']]);
        $this->accounts($request);
    }

    /** PATCH /api/accounting/accounts/{id} { code?, name, type?, description, status } (super admin) */
    public function updateAccount(Request $request): never
    {
        $db = Database::tenant();
        $stmt = $db->prepare('SELECT * FROM gl_accounts WHERE id = ?');
        $stmt->execute([(int) $request->param('id')]);
        $a = $stmt->fetch() ?: Response::error('Account not found.', 404);
        $d = $this->accountInput($request, $a);
        $status = $request->string('status') === 'archived' ? 'archived' : 'active';
        if ($status === 'archived' && $a['system_key']) {
            Response::error('The system posts to this account, so it can\'t be archived.', 422);
        }
        $db->prepare('UPDATE gl_accounts SET code = ?, name = ?, type = ?, description = ?, status = ? WHERE id = ?')
            ->execute([$d['code'], $d['name'], $d['type'], $d['description'], $status, $a['id']]);
        Audit::log('account.updated', 'gl_account', (int) $a['id'], ['before' => array_intersect_key($a, $d + ['status' => 1]), 'after' => $d + ['status' => $status]]);
        $this->accounts($request);
    }

    private function accountInput(Request $request, ?array $existing): array
    {
        $code = $request->string('code') ?: ($existing['code'] ?? '');
        $name = mb_substr($request->string('name'), 0, 120);
        $type = $request->string('type') ?: ($existing['type'] ?? '');
        if (!preg_match('/^[0-9A-Za-z.-]{1,10}$/', $code)) {
            Response::error('Give the account a code of up to 10 letters or digits, e.g. 5070.', 422, ['code' => 'Invalid']);
        }
        if ($name === '') {
            Response::error('Name the account.', 422, ['name' => 'Required']);
        }
        if (!isset(self::TYPES[$type])) {
            Response::error('Choose the kind of account.', 422, ['type' => 'Required']);
        }
        if ($existing && $existing['system_key'] && $type !== $existing['type']) {
            Response::error('The system posts to this account, so its kind can\'t change.', 422);
        }
        $db = Database::tenant();
        $dup = $db->prepare('SELECT 1 FROM gl_accounts WHERE code = ? AND id <> ?');
        $dup->execute([$code, $existing['id'] ?? 0]);
        if ($dup->fetchColumn()) {
            Response::error("Another account already has code $code.", 422, ['code' => 'Taken']);
        }
        return ['code' => $code, 'name' => $name, 'type' => $type, 'description' => mb_substr($request->string('description'), 0, 255) ?: null];
    }

    // --- Journal entries -----------------------------------------------------------------------

    /** GET /api/accounting/journal?from=&to=&kind=all|manual|auto&q=&page= */
    public function journal(Request $request): never
    {
        [$from, $to] = $this->range($request);
        $db = Database::tenant();
        Books::sync($db);
        $where = 'e.entry_date BETWEEN ? AND ?';
        $args = [$from, $to];
        $kind = $request->string('kind');
        if ($kind === 'manual' || $kind === 'auto') {
            $where .= ' AND e.auto = ?';
            $args[] = $kind === 'auto' ? 1 : 0;
        }
        if (($q = $request->string('q')) !== '') {
            $where .= ' AND (e.narration LIKE ? OR e.reference LIKE ? OR e.entry_no LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%");
        }
        $page = max(1, (int) $request->query('page', 1));
        $per = 50;
        $count = $db->prepare("SELECT COUNT(*) FROM journal_entries e WHERE $where");
        $count->execute($args);
        $stmt = $db->prepare(
            "SELECT e.*, u.full_name AS created_by_name, (SELECT r.id FROM journal_entries r WHERE r.reversal_of = e.id) AS reversed_by
             FROM journal_entries e LEFT JOIN users u ON u.id = e.created_by
             WHERE $where ORDER BY e.entry_date DESC, e.id DESC LIMIT $per OFFSET " . (($page - 1) * $per)
        );
        $stmt->execute($args);
        $entries = $stmt->fetchAll();
        $lines = [];
        if ($entries) {
            $in = implode(',', array_map(fn ($e) => (int) $e['id'], $entries));
            foreach ($db->query("SELECT l.*, a.code, a.name FROM journal_lines l JOIN gl_accounts a ON a.id = l.account_id WHERE l.entry_id IN ($in) ORDER BY l.debit = 0, l.id") as $l) {
                $lines[$l['entry_id']][] = ['code' => $l['code'], 'account' => $l['name'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'memo' => $l['memo']];
            }
        }
        Response::ok([
            'entries' => array_map(fn ($e) => [
                'id' => (int) $e['id'],
                'entry_no' => $e['entry_no'] ?? null,
                'entry_date' => $e['entry_date'],
                'narration' => $e['narration'],
                'reference' => $e['reference'],
                'auto' => (bool) $e['auto'],
                'source' => $e['source'],
                'reversal_of' => $e['reversal_of'] ? (int) $e['reversal_of'] : null,
                'reversed_by' => $e['reversed_by'] ? (int) $e['reversed_by'] : null,
                'created_by_name' => $e['created_by_name'],
                'lines' => $lines[$e['id']] ?? [],
                'total' => Money::toDecimal(array_sum(array_map(fn ($l) => Money::fromDecimal($l['debit']), $lines[$e['id']] ?? []))),
            ], $entries),
            'total' => (int) $count->fetchColumn(),
            'page' => $page,
            'per_page' => $per,
        ]);
    }

    /** POST /api/accounting/journal { entry_date, narration, reference?, lines: [{ account_id, debit, credit, memo }] } */
    public function createJournal(Request $request): never
    {
        $date = $this->date($request->string('entry_date') ?: date('Y-m-d'));
        $narration = mb_substr($request->string('narration'), 0, 255);
        if ($narration === '') {
            Response::error('Describe the entry, e.g. "Bank charges for October".', 422, ['narration' => 'Required']);
        }
        $db = Database::tenant();
        $accounts = $db->query("SELECT id, code, name, system_key, status FROM gl_accounts")->fetchAll(\PDO::FETCH_UNIQUE);
        $lines = [];
        $debits = $credits = 0;
        foreach ((array) $request->input('lines', []) as $i => $l) {
            $accountId = (int) ($l['account_id'] ?? 0);
            $debit = Money::parse(($l['debit'] ?? '') === '' ? '0' : $l['debit']);
            $credit = Money::parse(($l['credit'] ?? '') === '' ? '0' : $l['credit']);
            if (!$accountId && !$debit && !$credit) {
                continue;
            }
            $n = $i + 1;
            $a = $accounts[$accountId] ?? Response::error("Choose an account on line $n.", 422);
            if ($a['status'] !== 'active') {
                Response::error("{$a['name']} is archived.", 422);
            }
            if (in_array($a['system_key'], self::CONTROL, true)) {
                Response::error("{$a['name']} follows members' own accounts. Use a deposit, transfer, loan repayment or adjustment on the member instead, so their account and the books agree.", 422);
            }
            if ($debit === null || $credit === null || $debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
                Response::error("Line $n needs either a debit or a credit amount.", 422);
            }
            $debits += $debit;
            $credits += $credit;
            $lines[] = [$accountId, $debit, $credit, mb_substr(trim((string) ($l['memo'] ?? '')), 0, 255) ?: null];
        }
        if (count($lines) < 2) {
            Response::error('A journal entry needs at least two lines.', 422);
        }
        if ($debits !== $credits) {
            Response::error('Debits (' . number_format($debits / 100, 2) . ') and credits (' . number_format($credits / 100, 2) . ') must be equal.', 422);
        }
        $id = Database::transaction(function (\PDO $db) use ($date, $narration, $request, $lines) {
            $db->prepare("INSERT INTO journal_entries (entry_date, narration, reference, auto, source, created_by) VALUES (?, ?, ?, 0, 'manual', ?)")
                ->execute([$date, $narration, mb_substr($request->string('reference'), 0, 80) ?: null, Auth::id()]);
            $id = (int) $db->lastInsertId();
            $db->prepare('UPDATE journal_entries SET entry_no = ? WHERE id = ?')->execute(['JV' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
            $insert = $db->prepare('INSERT INTO journal_lines (entry_id, account_id, debit, credit, memo) VALUES (?, ?, ?, ?, ?)');
            foreach ($lines as [$account, $debit, $credit, $memo]) {
                $insert->execute([$id, $account, Money::toDecimal($debit), Money::toDecimal($credit), $memo]);
            }
            return $id;
        });
        Audit::log('journal.posted', 'journal', $id, ['amount' => Money::toDecimal($debits), 'note' => $narration]);
        Response::ok(['id' => $id], 201);
    }

    /** POST /api/accounting/journal/{id}/reverse { reason } - only entries typed in by the office */
    public function reverseJournal(Request $request): never
    {
        $db = Database::tenant();
        $stmt = $db->prepare('SELECT e.*, (SELECT r.id FROM journal_entries r WHERE r.reversal_of = e.id) AS reversed_by FROM journal_entries e WHERE e.id = ?');
        $stmt->execute([(int) $request->param('id')]);
        $e = $stmt->fetch() ?: Response::error('Entry not found.', 404);
        if ($e['auto']) {
            Response::error('This entry comes from a record in the system. Reverse that record (the deposit, repayment, payment...) instead.', 422);
        }
        if ($e['reversal_of'] || $e['reversed_by']) {
            Response::error('This entry has already been reversed.', 422);
        }
        $reason = mb_substr($request->string('reason'), 0, 200);
        if ($reason === '') {
            Response::error('Say why this entry is being reversed.', 422, ['reason' => 'Required']);
        }
        $id = Database::transaction(function (\PDO $db) use ($e, $reason) {
            $db->prepare("INSERT INTO journal_entries (entry_date, narration, reference, auto, source, reversal_of, created_by) VALUES (CURDATE(), ?, ?, 0, 'manual', ?, ?)")
                ->execute(["Reversal of {$e['entry_no']}: $reason", $e['reference'], $e['id'], Auth::id()]);
            $id = (int) $db->lastInsertId();
            $db->prepare('UPDATE journal_entries SET entry_no = ? WHERE id = ?')->execute(['JV' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
            $db->prepare('INSERT INTO journal_lines (entry_id, account_id, debit, credit, memo) SELECT ?, account_id, credit, debit, memo FROM journal_lines WHERE entry_id = ?')
                ->execute([$id, $e['id']]);
            return $id;
        });
        Audit::log('journal.reversed', 'journal', (int) $e['id'], ['reason' => $reason, 'reversal' => $id]);
        Response::ok(['id' => $id]);
    }

    /** POST /api/accounting/rebuild (super admin) - works every automatic entry out again */
    public function rebuild(Request $request): never
    {
        $count = Books::rebuild(Database::tenant());
        Response::ok(['entries' => $count]);
    }

    // --- Statements ----------------------------------------------------------------------------

    /** GET /api/accounting/reports/{name}?as_of= | from=&to= | month= | account_id= */
    public function report(Request $request): never
    {
        $name = $request->param('name');
        if (!isset(self::REPORTS[$name])) {
            Response::error('No such report.', 404);
        }
        $db = Database::tenant();
        Books::sync($db);
        [$title, $dates] = self::REPORTS[$name];
        $out = match ($dates) {
            'as_of' => $this->$name($db, $this->date($request->string('as_of') ?: date('Y-m-d'))),
            'range' => $this->$name($db, ...$this->range($request), request: $request),
            'month' => $this->$name($db, $this->month($request)),
        };
        Response::ok($out + ['title' => $title]);
    }

    private function trial_balance(\PDO $db, string $asOf): array
    {
        $totals = Books::totals($db, $asOf);
        $rows = [];
        $dr = $cr = 0;
        foreach (Books::chart($db) as $a) {
            $t = $totals[(int) $a['id']] ?? null;
            if (!$t) {
                continue;
            }
            $net = $t['d'] - $t['c'];
            if ($net === 0) {
                continue;
            }
            $net > 0 ? $dr += $net : $cr -= $net;
            $rows[] = ['code' => $a['code'], 'account' => $a['name'], 'type' => self::TYPES[$a['type']], 'debit' => $net > 0 ? Money::toDecimal($net) : null, 'credit' => $net < 0 ? Money::toDecimal(-$net) : null, '_account_id' => (int) $a['id']];
        }
        return [
            'subtitle' => 'As at ' . date('j F Y', strtotime($asOf)) . ($dr === $cr ? ' · balanced' : ' · NOT BALANCED by ' . number_format(abs($dr - $cr) / 100, 2)),
            'columns' => [['key' => 'code', 'label' => 'Code', 'type' => 'text'], ['key' => 'account', 'label' => 'Account', 'type' => 'text'], ['key' => 'type', 'label' => 'Kind', 'type' => 'text'], ['key' => 'debit', 'label' => 'Debit', 'type' => 'money'], ['key' => 'credit', 'label' => 'Credit', 'type' => 'money']],
            'rows' => $rows,
            'totals' => ['debit' => Money::toDecimal($dr), 'credit' => Money::toDecimal($cr)],
            'balanced' => $dr === $cr,
        ];
    }

    private function balance_sheet(\PDO $db, string $asOf): array
    {
        $totals = Books::totals($db, $asOf);
        $chart = Books::chart($db);
        $sum = ['asset' => 0, 'liability' => 0, 'equity' => 0, 'income' => 0, 'expense' => 0];
        $byType = [];
        foreach ($chart as $a) {
            $b = Books::balance($a, $totals);
            $sum[$a['type']] += $b;
            if ($b !== 0 && in_array($a['type'], ['asset', 'liability', 'equity'], true)) {
                $byType[$a['type']][] = ['code' => $a['code'], 'account' => $a['name'], 'amount' => Money::toDecimal($b), '_account_id' => (int) $a['id']];
            }
        }
        $surplus = $sum['income'] - $sum['expense'];
        $heading = fn ($t) => ['code' => '', 'account' => $t, 'amount' => null, '_kind' => 'heading'];
        $line = fn ($t, $v, $k = 'subtotal') => ['code' => '', 'account' => $t, 'amount' => Money::toDecimal($v), '_kind' => $k];

        $rows = [$heading('Assets'), ...($byType['asset'] ?? []), $line('Total assets', $sum['asset'])];
        $rows = [...$rows, $heading('Liabilities'), ...($byType['liability'] ?? []), $line('Total liabilities', $sum['liability'])];
        $rows = [...$rows, $heading('Members\' equity'), ...($byType['equity'] ?? []),
            ['code' => '', 'account' => 'Surplus to date (income less expenses)', 'amount' => Money::toDecimal($surplus)],
            $line('Total members\' equity', $sum['equity'] + $surplus)];
        $right = $sum['liability'] + $sum['equity'] + $surplus;
        $rows[] = $line('Total liabilities and members\' equity', $right, 'total');
        $balanced = $right === $sum['asset'];
        return [
            'subtitle' => 'As at ' . date('j F Y', strtotime($asOf)) . ($balanced ? ' · balanced' : ' · NOT BALANCED by ' . number_format(abs($right - $sum['asset']) / 100, 2)),
            'columns' => [['key' => 'code', 'label' => 'Code', 'type' => 'text'], ['key' => 'account', 'label' => 'Account', 'type' => 'text'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            'rows' => $rows,
            'totals' => null,
            'balanced' => $balanced,
        ];
    }

    private function income_statement(\PDO $db, string $from, string $to, ?Request $request = null): array
    {
        $totals = Books::totals($db, $to, $from);
        $sum = ['income' => 0, 'expense' => 0];
        $rows = ['income' => [], 'expense' => []];
        foreach (Books::chart($db) as $a) {
            if (!isset($sum[$a['type']])) {
                continue;
            }
            $b = Books::balance($a, $totals);
            if ($b !== 0) {
                $sum[$a['type']] += $b;
                $rows[$a['type']][] = ['code' => $a['code'], 'account' => $a['name'], 'amount' => Money::toDecimal($b), '_account_id' => (int) $a['id']];
            }
        }
        $surplus = $sum['income'] - $sum['expense'];
        return [
            'subtitle' => date('j F Y', strtotime($from)) . ' to ' . date('j F Y', strtotime($to)),
            'columns' => [['key' => 'code', 'label' => 'Code', 'type' => 'text'], ['key' => 'account', 'label' => 'Account', 'type' => 'text'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            'rows' => [
                ['code' => '', 'account' => 'Income', 'amount' => null, '_kind' => 'heading'],
                ...$rows['income'],
                ['code' => '', 'account' => 'Total income', 'amount' => Money::toDecimal($sum['income']), '_kind' => 'subtotal'],
                ['code' => '', 'account' => 'Expenses', 'amount' => null, '_kind' => 'heading'],
                ...$rows['expense'],
                ['code' => '', 'account' => 'Total expenses', 'amount' => Money::toDecimal($sum['expense']), '_kind' => 'subtotal'],
                ['code' => '', 'account' => $surplus >= 0 ? 'Surplus for the period' : 'Deficit for the period', 'amount' => Money::toDecimal($surplus), '_kind' => 'total'],
            ],
            'totals' => null,
        ];
    }

    private function account_ledger(\PDO $db, string $from, string $to, ?Request $request = null): array
    {
        $stmt = $db->prepare('SELECT * FROM gl_accounts WHERE id = ?');
        $stmt->execute([(int) $request?->query('account_id', 0)]);
        $a = $stmt->fetch() ?: Response::error('Choose an account.', 422);
        $before = Books::totals($db, date('Y-m-d', strtotime("$from -1 day")));
        $balance = Books::balance($a, $before);
        $normal = Books::debitNormal($a['type']) ? 1 : -1;
        $lines = $db->prepare(
            "SELECT e.id, e.entry_no, e.entry_date, e.narration, e.reference, l.debit, l.credit, l.memo
             FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
             WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date, e.id, l.id"
        );
        $lines->execute([$a['id'], $from, $to]);
        $rows = [['date' => $from, 'entry' => '', 'narration' => 'Opening balance', 'debit' => null, 'credit' => null, 'balance' => Money::toDecimal($balance), '_kind' => 'subtotal']];
        $dr = $cr = 0;
        foreach ($lines->fetchAll() as $l) {
            $d = Money::fromDecimal($l['debit']);
            $c = Money::fromDecimal($l['credit']);
            $dr += $d;
            $cr += $c;
            $balance += $normal * ($d - $c);
            $rows[] = [
                'date' => $l['entry_date'],
                'entry' => $l['entry_no'] ?: 'A' . $l['id'],
                'narration' => $l['narration'] . ($l['memo'] && $l['memo'] !== $l['narration'] ? ' · ' . $l['memo'] : ''),
                'debit' => $d ? Money::toDecimal($d) : null,
                'credit' => $c ? Money::toDecimal($c) : null,
                'balance' => Money::toDecimal($balance),
            ];
        }
        return [
            'subtitle' => "{$a['code']} {$a['name']} · " . date('j M Y', strtotime($from)) . ' to ' . date('j M Y', strtotime($to)),
            'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'entry', 'label' => 'Entry', 'type' => 'text'], ['key' => 'narration', 'label' => 'Details', 'type' => 'text'], ['key' => 'debit', 'label' => 'Debit', 'type' => 'money'], ['key' => 'credit', 'label' => 'Credit', 'type' => 'money'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money']],
            'rows' => $rows,
            'totals' => ['debit' => Money::toDecimal($dr), 'credit' => Money::toDecimal($cr), 'balance' => Money::toDecimal($balance)],
        ];
    }

    /** Interest each loan earned in a month, what was paid, and what is still owed */
    private function loan_interest(\PDO $db, string $month): array
    {
        $start = "$month-01";
        $end = date('Y-m-t', strtotime($start));
        $stmt = $db->prepare(
            "SELECT l.id, l.loan_no, u.full_name, u.account_no, l.interest_rate_monthly,
                (SELECT COALESCE(SUM(i.interest), 0) FROM loan_installments i WHERE i.loan_id = l.id AND i.due_date BETWEEN ? AND ?) AS due,
                (SELECT COALESCE(SUM(i.principal), 0) FROM loan_installments i WHERE i.loan_id = l.id AND i.due_date >= ?) AS principal_start,
                (SELECT COALESCE(SUM(a.amount), 0) FROM loan_payment_allocations a JOIN loan_payments p ON p.id = a.payment_id
                    WHERE a.loan_id = l.id AND a.part = 'interest' AND p.paid_on BETWEEN ? AND ?) AS paid,
                (SELECT COALESCE(SUM(i.interest - i.interest_paid), 0) FROM loan_installments i WHERE i.loan_id = l.id AND i.due_date <= ?) AS unpaid
             FROM loans l JOIN users u ON u.id = l.user_id
             WHERE l.disbursed_on IS NOT NULL AND l.disbursed_on <= ? AND l.status IN ('active', 'closed')
             HAVING due > 0 OR paid > 0 OR unpaid > 0
             ORDER BY u.full_name"
        );
        $stmt->execute([$start, $end, $start, $start, $end, $end, $end]);
        $rows = [];
        $sum = ['principal_start' => 0, 'due' => 0, 'paid' => 0, 'unpaid' => 0];
        foreach ($stmt->fetchAll() as $r) {
            foreach ($sum as $k => $_) {
                $sum[$k] += Money::fromDecimal($r[$k]);
            }
            $rows[] = [
                'loan_no' => $r['loan_no'],
                'member' => $r['full_name'],
                'account_no' => $r['account_no'],
                'rate' => rtrim(rtrim($r['interest_rate_monthly'], '0'), '.') . '%',
                'principal_start' => $r['principal_start'],
                'due' => $r['due'],
                'paid' => $r['paid'],
                'unpaid' => max('0', $r['unpaid']),
            ];
        }
        return [
            'subtitle' => date('F Y', strtotime($start)) . ' · interest on what each borrower still owed, as the instalments fell due',
            'columns' => [
                ['key' => 'loan_no', 'label' => 'Loan', 'type' => 'text'], ['key' => 'member', 'label' => 'Member', 'type' => 'text'],
                ['key' => 'account_no', 'label' => 'Account no.', 'type' => 'text'], ['key' => 'rate', 'label' => 'Rate a month', 'type' => 'text'],
                ['key' => 'principal_start', 'label' => 'Principal owed at start', 'type' => 'money'], ['key' => 'due', 'label' => 'Interest for the month', 'type' => 'money'],
                ['key' => 'paid', 'label' => 'Interest paid in the month', 'type' => 'money'], ['key' => 'unpaid', 'label' => 'Interest still owed', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => array_map([Money::class, 'toDecimal'], $sum),
        ];
    }

    // --- Inputs --------------------------------------------------------------------------------

    private function date(string $d): string
    {
        $p = \DateTime::createFromFormat('!Y-m-d', $d);
        if (!$p || $p->format('Y-m-d') !== $d) {
            Response::error('Enter a valid date.', 422);
        }
        return $d;
    }

    /** @return array{0: string, 1: string} */
    private function range(Request $request): array
    {
        $from = $this->date($request->string('from') ?: date('Y-01-01'));
        $to = $this->date($request->string('to') ?: date('Y-m-d'));
        if ($from > $to) {
            Response::error('The start date is after the end date.', 422);
        }
        return [$from, $to];
    }

    private function month(Request $request): string
    {
        $m = $request->string('month') ?: date('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            Response::error('Choose a month.', 422);
        }
        return $m;
    }
}
