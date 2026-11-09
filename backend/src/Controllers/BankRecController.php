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
 * Bank reconciliation. Enter the statement's date and closing balance, tick the entries in the
 * books that appear on the statement, and when everything ticked so far adds up to the
 * statement's balance the reconciliation can be completed. What stays unticked is explained on
 * the reconciliation statement: deposits not yet banked and cheques not yet presented.
 */
final class BankRecController
{
    /** GET /api/bank-recs */
    public function index(Request $request): never
    {
        $db = Database::tenant();
        Books::sync($db);
        $totals = Books::totals($db, date('Y-m-d'));
        $accounts = array_values(array_filter(Books::chart($db), fn ($a) => $a['type'] === 'asset' && $a['status'] === 'active'
            && (in_array($a['system_key'], ['bank', 'cash'], true) || $a['system_key'] === null)));
        $recs = $db->query(
            'SELECT r.*, a.code, a.name AS account_name, u.full_name AS completed_by_name FROM bank_reconciliations r
             JOIN gl_accounts a ON a.id = r.account_id LEFT JOIN users u ON u.id = r.completed_by
             ORDER BY r.statement_date DESC, r.id DESC LIMIT 60'
        )->fetchAll();
        Response::ok([
            'accounts' => array_map(fn ($a) => ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'balance' => Money::toDecimal(Books::balance($a, $totals))], $accounts),
            'reconciliations' => $recs,
        ]);
    }

    /** POST /api/bank-recs { account_id, statement_date, statement_balance } */
    public function store(Request $request): never
    {
        $db = Database::tenant();
        $accountId = (int) $request->input('account_id');
        $a = $db->prepare("SELECT id FROM gl_accounts WHERE id = ? AND type = 'asset'");
        $a->execute([$accountId]);
        if (!$a->fetchColumn()) {
            Response::error('Choose the bank or cash account.', 422, ['account_id' => 'Required']);
        }
        $open = $db->prepare("SELECT id FROM bank_reconciliations WHERE account_id = ? AND status = 'open'");
        $open->execute([$accountId]);
        if ($id = $open->fetchColumn()) {
            Response::error('This account already has a reconciliation in progress. Finish or delete it first.', 422, ['open_id' => (string) $id]);
        }
        [$date, $balance] = $this->inputs($request, $accountId);
        $db->prepare('INSERT INTO bank_reconciliations (account_id, statement_date, statement_balance, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$accountId, $date, Money::toDecimal($balance), Auth::id()]);
        $id = (int) $db->lastInsertId();
        Audit::log('bank_rec.started', 'bank_rec', $id, ['amount' => Money::toDecimal($balance)]);
        Response::ok(['id' => $id], 201);
    }

    /** GET /api/bank-recs/{id} */
    public function show(Request $request): never
    {
        Response::ok($this->detail($this->find((int) $request->param('id'))));
    }

    /** PATCH /api/bank-recs/{id} { statement_date, statement_balance } */
    public function update(Request $request): never
    {
        $r = $this->openOne($request);
        [$date, $balance] = $this->inputs($request, (int) $r['account_id']);
        Database::tenant()->prepare('UPDATE bank_reconciliations SET statement_date = ?, statement_balance = ? WHERE id = ?')
            ->execute([$date, Money::toDecimal($balance), $r['id']]);
        Response::ok($this->detail($this->find((int) $r['id'])));
    }

    /** POST /api/bank-recs/{id}/tick { keys: [...], cleared: bool } */
    public function tick(Request $request): never
    {
        $r = $this->openOne($request);
        $db = Database::tenant();
        $lines = $this->lines($db, $r);
        $keys = array_slice(array_map('strval', (array) $request->input('keys', [])), 0, 2000);
        if ($request->input('cleared')) {
            $insert = $db->prepare('INSERT IGNORE INTO bank_rec_items (reconciliation_id, line_key, amount) VALUES (?, ?, ?)');
            foreach ($keys as $key) {
                if (isset($lines[$key]) && !$lines[$key]['elsewhere']) {
                    $insert->execute([$r['id'], $key, Money::toDecimal($lines[$key]['cents'])]);
                }
            }
        } else {
            $delete = $db->prepare('DELETE FROM bank_rec_items WHERE reconciliation_id = ? AND line_key = ?');
            foreach ($keys as $key) {
                $delete->execute([$r['id'], $key]);
            }
        }
        Response::ok($this->detail($this->find((int) $r['id'])));
    }

    /** POST /api/bank-recs/{id}/complete - only when the ticked entries agree with the statement */
    public function complete(Request $request): never
    {
        $r = $this->openOne($request);
        $d = $this->detail($r);
        if (Money::fromDecimal($d['difference']) !== 0) {
            Response::error('It doesn\'t agree yet: there is a difference of ' . number_format(abs(Money::fromDecimal($d['difference'])) / 100, 2) . ' between the statement and the ticked entries.', 422);
        }
        Database::tenant()->prepare("UPDATE bank_reconciliations SET status = 'completed', completed_by = ?, completed_at = NOW() WHERE id = ?")->execute([Auth::id(), $r['id']]);
        Audit::log('bank_rec.completed', 'bank_rec', (int) $r['id'], ['amount' => $r['statement_balance']]);
        Response::ok($this->detail($this->find((int) $r['id'])));
    }

    /** POST /api/bank-recs/{id}/reopen (super admin) - only the account's latest completed one */
    public function reopen(Request $request): never
    {
        $r = $this->find((int) $request->param('id'));
        $db = Database::tenant();
        $latest = $db->prepare("SELECT id FROM bank_reconciliations WHERE account_id = ? AND status = 'completed' ORDER BY statement_date DESC, id DESC LIMIT 1");
        $latest->execute([$r['account_id']]);
        if ($r['status'] !== 'completed' || (int) $latest->fetchColumn() !== (int) $r['id']) {
            Response::error('Only the most recent completed reconciliation can be reopened.', 422);
        }
        $open = $db->prepare("SELECT 1 FROM bank_reconciliations WHERE account_id = ? AND status = 'open'");
        $open->execute([$r['account_id']]);
        if ($open->fetchColumn()) {
            Response::error('Finish or delete the reconciliation in progress first.', 422);
        }
        $db->prepare("UPDATE bank_reconciliations SET status = 'open', completed_by = NULL, completed_at = NULL WHERE id = ?")->execute([$r['id']]);
        Audit::log('bank_rec.reopened', 'bank_rec', (int) $r['id']);
        Response::ok($this->detail($this->find((int) $r['id'])));
    }

    /** DELETE /api/bank-recs/{id} - one still in progress */
    public function destroy(Request $request): never
    {
        $r = $this->openOne($request);
        Database::tenant()->prepare('DELETE FROM bank_reconciliations WHERE id = ?')->execute([$r['id']]);
        Audit::log('bank_rec.deleted', 'bank_rec', (int) $r['id']);
        Response::ok();
    }

    // --- Helpers -------------------------------------------------------------------------------

    /** The account's entries up to the statement date, by their lasting key */
    private function lines(\PDO $db, array $r): array
    {
        Books::sync($db);
        $stmt = $db->prepare(
            'SELECT e.id, e.entry_no, e.entry_date, e.narration, e.reference, e.auto, e.source, e.source_id, l.debit, l.credit
             FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
             WHERE l.account_id = ? AND e.entry_date <= ? ORDER BY e.entry_date, e.id'
        );
        $stmt->execute([$r['account_id'], $r['statement_date']]);
        // Cleared on another reconciliation of this account
        $done = $db->prepare('SELECT i.line_key FROM bank_rec_items i JOIN bank_reconciliations b ON b.id = i.reconciliation_id WHERE b.account_id = ? AND b.id <> ?');
        $done->execute([$r['account_id'], $r['id']]);
        $elsewhere = array_flip($done->fetchAll(\PDO::FETCH_COLUMN));

        $out = [];
        foreach ($stmt->fetchAll() as $l) {
            $origin = $l['auto'] ? "{$l['source']}:{$l['source_id']}" : "manual:{$l['id']}";
            $key = "$origin:{$l['entry_date']}:{$l['debit']}:{$l['credit']}";
            $cents = Money::fromDecimal($l['debit']) - Money::fromDecimal($l['credit']);
            // Two identical entries from one source are told apart by a counter
            $n = 2;
            $base = $key;
            while (isset($out[$key])) {
                $key = "$base:" . $n++;
            }
            $out[$key] = [
                'key' => $key,
                'date' => $l['entry_date'],
                'details' => $l['narration'],
                'reference' => $l['reference'],
                'cents' => $cents,
                'elsewhere' => isset($elsewhere[$key]),
            ];
        }
        return $out;
    }

    private function detail(array $r): array
    {
        $db = Database::tenant();
        $lines = $this->lines($db, $r);
        $ticked = $db->prepare('SELECT line_key FROM bank_rec_items WHERE reconciliation_id = ?');
        $ticked->execute([$r['id']]);
        $ticked = array_flip($ticked->fetchAll(\PDO::FETCH_COLUMN));

        // Everything cleared so far: on earlier reconciliations (fixed amounts) and on this one
        $before = $db->prepare('SELECT COALESCE(SUM(i.amount), 0) FROM bank_rec_items i JOIN bank_reconciliations b ON b.id = i.reconciliation_id WHERE b.account_id = ? AND b.id <> ? AND b.status = \'completed\'');
        $before->execute([$r['account_id'], $r['id']]);
        $cleared = Money::fromDecimal($before->fetchColumn());
        $book = 0;
        $items = [];
        $deposits = [];
        $payments = [];
        foreach ($lines as $key => $l) {
            $book += $l['cents'];
            if ($l['elsewhere']) {
                continue;
            }
            $isTicked = isset($ticked[$key]);
            if ($isTicked) {
                $cleared += $l['cents'];
            } elseif ($l['cents'] > 0) {
                $deposits[] = $l;
            } else {
                $payments[] = $l;
            }
            $items[] = [
                'key' => $key,
                'date' => $l['date'],
                'details' => $l['details'],
                'reference' => $l['reference'],
                'amount' => Money::toDecimal($l['cents']),
                'ticked' => $isTicked,
            ];
        }
        $statement = Money::fromDecimal($r['statement_balance']);
        $difference = $statement - $cleared;
        $sumDeposits = array_sum(array_column($deposits, 'cents'));
        $sumPayments = -array_sum(array_column($payments, 'cents'));
        $adjusted = $statement + $sumDeposits - $sumPayments;

        $row = fn ($date, $ref, $details, $amount, $kind = null) => array_filter(['date' => $date, 'reference' => $ref, 'details' => $details, 'amount' => $amount === null ? null : Money::toDecimal($amount), '_kind' => $kind], fn ($v) => $v !== null);
        $rows = [$row(null, null, 'Balance on the bank statement', $statement, 'subtotal'), $row(null, null, 'Add: money in the books, not yet on the statement', null, 'heading')];
        foreach ($deposits as $l) {
            $rows[] = $row($l['date'], $l['reference'], $l['details'], $l['cents']);
        }
        $rows[] = $row(null, null, 'Total to add', $sumDeposits, 'subtotal');
        $rows[] = $row(null, null, 'Less: payments in the books, not yet on the statement', null, 'heading');
        foreach ($payments as $l) {
            $rows[] = $row($l['date'], $l['reference'], $l['details'], -$l['cents']);
        }
        $rows[] = $row(null, null, 'Total to take off', $sumPayments, 'subtotal');
        $rows[] = $row(null, null, 'Adjusted bank balance', $adjusted, 'subtotal');
        $rows[] = $row(null, null, 'Balance in the books', $book, 'total');

        return [
            'reconciliation' => $r + ['can_tick' => $r['status'] === 'open'],
            'items' => $items,
            'statement_balance' => Money::toDecimal($statement),
            'cleared' => Money::toDecimal($cleared),
            'difference' => Money::toDecimal($difference),
            'book_balance' => Money::toDecimal($book),
            'report' => [
                'title' => 'Bank reconciliation statement',
                'subtitle' => "{$r['code']} {$r['account_name']} · as at " . date('j F Y', strtotime($r['statement_date'])) . ($r['status'] === 'completed' ? ' · completed' : ' · in progress'),
                'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'reference', 'label' => 'Reference', 'type' => 'text'], ['key' => 'details', 'label' => 'Details', 'type' => 'text'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
                'rows' => $rows,
                'totals' => null,
                'balanced' => $adjusted === $book,
            ],
        ];
    }

    /** @return array{0: string, 1: int} */
    private function inputs(Request $request, int $accountId): array
    {
        $date = $request->string('statement_date');
        $d = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            Response::error('Enter the statement date, not in the future.', 422, ['statement_date' => 'Invalid']);
        }
        $last = Database::tenant()->prepare("SELECT MAX(statement_date) FROM bank_reconciliations WHERE account_id = ? AND status = 'completed'");
        $last->execute([$accountId]);
        if (($max = $last->fetchColumn()) && $date <= $max) {
            Response::error('This account is already reconciled up to ' . date('j M Y', strtotime($max)) . '. Choose a later statement date.', 422, ['statement_date' => 'Too early']);
        }
        $raw = trim((string) $request->input('statement_balance', ''));
        $negative = str_starts_with($raw, '-');
        $cents = Money::parse(ltrim($raw, '-'));
        if ($cents === null) {
            Response::error('Enter the closing balance on the statement.', 422, ['statement_balance' => 'Required']);
        }
        return [$date, $negative ? -$cents : $cents];
    }

    private function openOne(Request $request): array
    {
        $r = $this->find((int) $request->param('id'));
        if ($r['status'] !== 'open') {
            Response::error('This reconciliation is completed.', 422);
        }
        return $r;
    }

    private function find(int $id): array
    {
        $stmt = Database::tenant()->prepare('SELECT r.*, a.code, a.name AS account_name, u.full_name AS completed_by_name FROM bank_reconciliations r JOIN gl_accounts a ON a.id = r.account_id LEFT JOIN users u ON u.id = r.completed_by WHERE r.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: Response::error('Reconciliation not found.', 404);
    }
}
