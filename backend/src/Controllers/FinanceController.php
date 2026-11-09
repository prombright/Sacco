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
use Sacco\Support\Periods;

/**
 * Year-end closing and budgets.
 *
 * Closing a year moves its income, expenses and payouts to members into Retained surplus with one
 * journal entry dated 31 December, and locks the year: nothing more can be dated in it. Only the
 * latest closed year can be reopened, by the super admin.
 */
final class FinanceController
{
    /** GET /api/finance/years - each year with entries: open or closed, and its result */
    public function years(Request $request): never
    {
        $db = Database::tenant();
        Books::sync($db);
        $first = $db->query("SELECT MIN(entry_date) FROM journal_entries WHERE source <> 'year_end'")->fetchColumn();
        $firstYear = $first ? (int) substr($first, 0, 4) : (int) date('Y');
        $closed = $db->query('SELECT c.*, u.full_name AS closed_by_name FROM closed_years c LEFT JOIN users u ON u.id = c.closed_by')->fetchAll(\PDO::FETCH_UNIQUE);
        $years = [];
        for ($y = (int) date('Y'); $y >= $firstYear; $y--) {
            $r = $this->result($db, $y);
            $c = $closed[$y] ?? null;
            $drift = null;
            if ($c) {
                // Has anything in the year moved since it was closed?
                $now = $this->snapshot($db, $y);
                $drift = $now !== $c['snapshot'];
            }
            $years[] = [
                'year' => $y,
                'status' => $c ? 'closed' : ($y < (int) date('Y') ? 'ready' : 'running'),
                'income' => Money::toDecimal($r['income']),
                'expenses' => Money::toDecimal($r['expense']),
                'surplus' => Money::toDecimal($r['income'] - $r['expense']),
                'distributions' => Money::toDecimal($r['distributions']),
                'closed_at' => $c['closed_at'] ?? null,
                'closed_by' => $c['closed_by_name'] ?? null,
                'changed_since_closing' => $drift,
            ];
        }
        Response::ok(['years' => $years, 'last_closed' => Periods::lastClosedYear($db)]);
    }

    /** POST /api/finance/years/{year}/close (super admin) */
    public function close(Request $request): never
    {
        $year = (int) $request->param('year');
        $db = Database::tenant();
        Books::sync($db);
        if ($year >= (int) date('Y')) {
            Response::error("$year isn't over yet. A year can be closed from 1 January of the next year.", 422);
        }
        $last = Periods::lastClosedYear($db);
        if ($last !== null && $year <= $last) {
            Response::error("$year is already closed.", 422);
        }
        $first = $db->query("SELECT MIN(YEAR(entry_date)) FROM journal_entries WHERE source <> 'year_end'")->fetchColumn();
        $from = $last !== null ? $last + 1 : (int) $first;
        if ($from && $year > $from) {
            Response::error("Close $from first: years are closed in order.", 422);
        }
        $start = "$year-01-01";
        $end = "$year-12-31";
        $totals = Books::totals($db, $end, $start, false);
        $retained = Books::account($db, 'retained_surplus');
        $lines = [];
        $net = 0;
        foreach (Books::chart($db) as $a) {
            if (!in_array($a['type'], ['income', 'expense'], true) && $a['system_key'] !== 'distributions') {
                continue;
            }
            $t = $totals[(int) $a['id']] ?? null;
            if (!$t || $t['d'] === $t['c']) {
                continue;
            }
            $balance = $t['d'] - $t['c']; // debit balance positive
            // Bring the account to zero; the difference goes to Retained surplus
            $lines[] = [(int) $a['id'], $balance < 0 ? -$balance : 0, $balance > 0 ? $balance : 0];
            $net += $balance;
        }
        $r = $this->result($db, $year);
        $id = Database::transaction(function (\PDO $db) use ($lines, $net, $retained, $year, $end, $r) {
            $journalId = null;
            if ($lines) {
                $lines[] = [$retained, $net > 0 ? $net : 0, $net < 0 ? -$net : 0];
                $db->prepare("INSERT INTO journal_entries (entry_date, narration, reference, auto, source, source_id, created_by) VALUES (?, ?, ?, 0, 'year_end', ?, ?)")
                    ->execute([$end, "Year-end closing $year: the year's result to Retained surplus", "Close $year", $year, Auth::id()]);
                $journalId = (int) $db->lastInsertId();
                $db->prepare('UPDATE journal_entries SET entry_no = ? WHERE id = ?')->execute(['YE' . $year, $journalId]);
                $insert = $db->prepare('INSERT INTO journal_lines (entry_id, account_id, debit, credit, memo) VALUES (?, ?, ?, ?, ?)');
                foreach ($lines as [$account, $debit, $credit]) {
                    $insert->execute([$journalId, $account, Money::toDecimal($debit), Money::toDecimal($credit), "Closing $year"]);
                }
            }
            $db->prepare('INSERT INTO closed_years (year, journal_id, surplus, snapshot, closed_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$year, $journalId, Money::toDecimal($r['income'] - $r['expense']), $this->snapshot($db, $year), Auth::id()]);
            return $journalId;
        });
        Periods::forget();
        Audit::log('year.closed', null, null, ['year' => $year, 'amount' => Money::toDecimal($r['income'] - $r['expense'])]);
        Response::ok(['journal_id' => $id]);
    }

    /** POST /api/finance/years/{year}/reopen (super admin) - the latest closed year only */
    public function reopen(Request $request): never
    {
        $year = (int) $request->param('year');
        $db = Database::tenant();
        if (Periods::lastClosedYear($db) !== $year) {
            Response::error('Only the most recently closed year can be reopened.', 422);
        }
        $reason = mb_substr($request->string('reason'), 0, 255);
        if ($reason === '') {
            Response::error('Say why the year is being reopened.', 422, ['reason' => 'Required']);
        }
        Database::transaction(function (\PDO $db) use ($year) {
            $db->prepare("DELETE FROM journal_entries WHERE source = 'year_end' AND source_id = ?")->execute([$year]);
            $db->prepare('DELETE FROM closed_years WHERE year = ?')->execute([$year]);
        });
        Periods::forget();
        Audit::log('year.reopened', null, null, ['year' => $year, 'reason' => $reason]);
        Response::ok();
    }

    /** GET /api/finance/budget?year= - each income and expense account: budget, actual, difference */
    public function budget(Request $request): never
    {
        $year = $this->year($request);
        $db = Database::tenant();
        Books::sync($db);
        $to = min("$year-12-31", date('Y-m-d'));
        $totals = $year <= (int) date('Y') ? Books::totals($db, $to, "$year-01-01", false) : [];
        $last = Books::totals($db, ($year - 1) . '-12-31', ($year - 1) . '-01-01', false);
        $stmt = $db->prepare('SELECT account_id, amount FROM budgets WHERE year = ?');
        $stmt->execute([$year]);
        $budgets = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        // How far through the year we are, to judge whether spending is running ahead
        $elapsed = $year < (int) date('Y') ? 1 : ($year > (int) date('Y') ? 0 : (date('z') + 1) / (date('L') ? 366 : 365));

        $accounts = [];
        $rows = [];
        $sum = ['income' => ['b' => 0, 'a' => 0], 'expense' => ['b' => 0, 'a' => 0]];
        foreach (['income' => 'Income', 'expense' => 'Expenses'] as $type => $heading) {
            $rows[] = ['account' => $heading, '_kind' => 'heading'];
            foreach (Books::chart($db) as $a) {
                if ($a['type'] !== $type || ($a['status'] !== 'active' && !isset($budgets[$a['id']]))) {
                    continue;
                }
                $budget = Money::fromDecimal($budgets[$a['id']] ?? null);
                $actual = Books::balance($a, $totals);
                $sum[$type]['b'] += $budget;
                $sum[$type]['a'] += $actual;
                $accounts[] = ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'type' => $type, 'budget' => Money::toDecimal($budget), 'actual' => Money::toDecimal($actual), 'last_year' => Money::toDecimal(Books::balance($a, $last))];
                if ($budget || $actual) {
                    $rows[] = $this->budgetRow($a['code'] . ' ' . $a['name'], $budget, $actual, $type, $elapsed, (int) $a['id']);
                }
            }
            $rows[] = $this->budgetRow("Total $heading", $sum[$type]['b'], $sum[$type]['a'], $type, $elapsed, null, 'subtotal');
        }
        $rows[] = $this->budgetRow('Surplus', $sum['income']['b'] - $sum['expense']['b'], $sum['income']['a'] - $sum['expense']['a'], 'income', $elapsed, null, 'total');
        Response::ok([
            'year' => $year,
            'elapsed_percent' => (int) round($elapsed * 100),
            'accounts' => $accounts,
            'report' => [
                'title' => "Budget and actual $year",
                'subtitle' => ($year < (int) date('Y') ? 'The whole year' : '1 January to ' . date('j F Y', strtotime($to)) . ', ' . round($elapsed * 100) . '% of the year gone'),
                'columns' => [
                    ['key' => 'account', 'label' => 'Account', 'type' => 'text'], ['key' => 'budget', 'label' => 'Budget', 'type' => 'money'],
                    ['key' => 'actual', 'label' => 'Actual', 'type' => 'money'], ['key' => 'difference', 'label' => 'Difference', 'type' => 'money'],
                    ['key' => 'used', 'label' => 'Of budget', 'type' => 'text'], ['key' => 'note', 'label' => 'How it\'s going', 'type' => 'text'],
                ],
                'rows' => $rows,
                'totals' => null,
            ],
        ]);
    }

    /** PUT /api/finance/budget { year, lines: [{ account_id, amount }] } (super admin) */
    public function saveBudget(Request $request): never
    {
        $year = $this->year($request);
        $db = Database::tenant();
        $valid = $db->query("SELECT id FROM gl_accounts WHERE type IN ('income', 'expense')")->fetchAll(\PDO::FETCH_COLUMN);
        $lines = [];
        foreach ((array) $request->input('lines', []) as $l) {
            $id = (int) ($l['account_id'] ?? 0);
            $raw = trim((string) ($l['amount'] ?? ''));
            $c = $raw === '' ? 0 : Money::parse($raw);
            if (!in_array($id, array_map('intval', $valid), true) || $c === null || $c < 0) {
                Response::error('Each budget must be an amount of zero or more.', 422);
            }
            $lines[$id] = $c;
        }
        Database::transaction(function (\PDO $db) use ($year, $lines) {
            $db->prepare('DELETE FROM budgets WHERE year = ?')->execute([$year]);
            $insert = $db->prepare('INSERT INTO budgets (year, account_id, amount, updated_by) VALUES (?, ?, ?, ?)');
            foreach ($lines as $id => $c) {
                if ($c > 0) {
                    $insert->execute([$year, $id, Money::toDecimal($c), Auth::id()]);
                }
            }
        });
        Audit::log('budget.saved', null, null, ['year' => $year, 'amount' => Money::toDecimal(array_sum($lines))]);
        $this->budget($request);
    }

    // --- Helpers -------------------------------------------------------------------------------

    private function budgetRow(string $label, int $budget, int $actual, string $type, float $elapsed, ?int $accountId, ?string $kind = null): array
    {
        $used = $budget > 0 ? (int) round($actual / $budget * 100) : null;
        $note = '';
        if ($budget > 0) {
            $pace = $elapsed > 0 ? $actual / $budget / $elapsed : 0;
            $note = $type === 'expense'
                ? ($actual > $budget ? 'Over budget' : ($pace > 1.1 ? 'Spending ahead of the year' : 'Within budget'))
                : ($actual >= $budget ? 'Target reached' : ($pace < 0.9 ? 'Behind target' : 'On track'));
        } elseif ($actual) {
            $note = 'Not budgeted';
        }
        return array_filter([
            'account' => $label,
            'budget' => Money::toDecimal($budget),
            'actual' => Money::toDecimal($actual),
            'difference' => Money::toDecimal($type === 'expense' ? $budget - $actual : $actual - $budget),
            'used' => $used !== null ? "$used%" : '',
            'note' => $note,
            '_kind' => $kind,
            '_account_id' => $accountId,
        ], fn ($v) => $v !== null);
    }

    /** The year's income, expenses and payouts to members, from the books without closing entries */
    private function result(\PDO $db, int $year): array
    {
        $t = Books::totals($db, "$year-12-31", "$year-01-01", false);
        $out = ['income' => 0, 'expense' => 0, 'distributions' => 0];
        foreach (Books::chart($db) as $a) {
            if ($a['type'] === 'income') {
                $out['income'] += Books::balance($a, $t);
            } elseif ($a['type'] === 'expense') {
                $out['expense'] += Books::balance($a, $t);
            } elseif ($a['system_key'] === 'distributions') {
                $out['distributions'] -= Books::balance($a, $t);
            }
        }
        return $out;
    }

    /** Every account's movement in the year, to tell later whether anything in it changed */
    private function snapshot(\PDO $db, int $year): string
    {
        $t = Books::totals($db, "$year-12-31", "$year-01-01", false);
        ksort($t);
        return md5(json_encode($t));
    }

    private function year(Request $request): int
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        if ($year < 2000 || $year > (int) date('Y') + 1) {
            Response::error('Choose a year.', 422);
        }
        return $year;
    }
}
