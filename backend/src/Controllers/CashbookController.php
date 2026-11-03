<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Support\Money;

/**
 * The SACCO's own income and expenses besides loans: bank interest, membership fees, stationery,
 * bank charges, AGM costs... Loan interest, penalties and processing fees are counted
 * automatically from the loans and don't go here. Rows are only added; mistakes are reversed.
 */
final class CashbookController
{
    public const INCOME_CATEGORIES = ['Membership fees', 'Bank interest', 'Grants and donations', 'Other income'];
    public const EXPENSE_CATEGORIES = ['Stationery and printing', 'Bank charges', 'Meetings and AGM', 'Allowances', 'Audit fees', 'Transport', 'Communication', 'Other expenses'];

    /** GET /api/cashbook?from=&to= */
    public function index(Request $request): never
    {
        [$from, $to] = $this->period($request);
        $stmt = Database::tenant()->prepare(
            'SELECT c.*, u.full_name AS created_by_name, (SELECT r.id FROM cashbook r WHERE r.reversal_of = c.id) AS reversed_by
             FROM cashbook c LEFT JOIN users u ON u.id = c.created_by
             WHERE c.entry_date BETWEEN ? AND ? ORDER BY c.entry_date DESC, c.id DESC'
        );
        $stmt->execute([$from, $to]);
        $rows = $stmt->fetchAll();
        $income = $expense = 0;
        foreach ($rows as $r) {
            $r['kind'] === 'income' ? $income += Money::fromDecimal($r['amount']) : $expense += Money::fromDecimal($r['amount']);
        }
        Response::ok([
            'entries' => $rows,
            'from' => $from,
            'to' => $to,
            'income' => Money::toDecimal($income),
            'expenses' => Money::toDecimal($expense),
            'categories' => ['income' => self::INCOME_CATEGORIES, 'expense' => self::EXPENSE_CATEGORIES],
            'loan_income' => self::loanIncome($from, $to),
        ]);
    }

    /** POST /api/cashbook { kind, category, amount, entry_date, description, reference } */
    public function store(Request $request): never
    {
        $kind = $request->string('kind');
        if (!in_array($kind, ['income', 'expense'], true)) {
            Response::error('Choose income or expense.', 422, ['kind' => 'Required']);
        }
        $category = mb_substr($request->string('category'), 0, 60);
        if ($category === '') {
            Response::error('Choose a category.', 422, ['category' => 'Required']);
        }
        $cents = Money::parse($request->input('amount'));
        if ($cents === null || $cents <= 0) {
            Response::error('Enter an amount greater than zero.', 422, ['amount' => 'Required']);
        }
        $date = $request->string('entry_date') ?: date('Y-m-d');
        $d = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            Response::error('Enter a valid date, not in the future.', 422, ['entry_date' => 'Invalid']);
        }
        $description = mb_substr($request->string('description'), 0, 255);
        if ($description === '') {
            Response::error('Describe the entry, e.g. "Receipt books for the office".', 422, ['description' => 'Required']);
        }
        $db = Database::tenant();
        $db->prepare('INSERT INTO cashbook (kind, category, amount, entry_date, description, reference, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$kind, $category, Money::toDecimal($cents), $date, $description, mb_substr($request->string('reference'), 0, 80) ?: null, Auth::id()]);
        $id = (int) $db->lastInsertId();
        Audit::log('cashbook.recorded', 'cashbook', $id, ['kind' => $kind, 'amount' => Money::toDecimal($cents), 'type' => $kind]);
        Response::ok(['id' => $id], 201);
    }

    /** POST /api/cashbook/{id}/reverse { reason } */
    public function reverse(Request $request): never
    {
        $db = Database::tenant();
        $stmt = $db->prepare('SELECT c.*, (SELECT r.id FROM cashbook r WHERE r.reversal_of = c.id) AS reversed_by FROM cashbook c WHERE c.id = ?');
        $stmt->execute([(int) $request->param('id')]);
        $entry = $stmt->fetch() ?: Response::error('Entry not found.', 404);
        if ($entry['reversal_of'] || $entry['reversed_by']) {
            Response::error('This entry has already been reversed.', 422);
        }
        $reason = mb_substr($request->string('reason'), 0, 200);
        if ($reason === '') {
            Response::error('Say why this entry is being reversed.', 422, ['reason' => 'Required']);
        }
        $db->prepare('INSERT INTO cashbook (kind, category, amount, entry_date, description, reference, reversal_of, created_by) VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?)')
            ->execute([$entry['kind'], $entry['category'], Money::toDecimal(-Money::fromDecimal($entry['amount'])), "Reversed: $reason", $entry['reference'], $entry['id'], Auth::id()]);
        Audit::log('cashbook.reversed', 'cashbook', (int) $entry['id'], ['reason' => $reason]);
        Response::ok();
    }

    /** Income the loans earned in a period, in cents: interest and penalties received, and processing fees */
    public static function loanIncome(string $from, string $to): array
    {
        $db = Database::tenant();
        $stmt = $db->prepare(
            'SELECT a.part, COALESCE(SUM(a.amount), 0) FROM loan_payment_allocations a JOIN loan_payments p ON p.id = a.payment_id
             WHERE p.paid_on BETWEEN ? AND ? AND a.part IN (\'interest\', \'penalty\', \'fee\') GROUP BY a.part'
        );
        $stmt->execute([$from, $to]);
        $parts = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        $fees = $db->prepare('SELECT COALESCE(SUM(processing_fee), 0) FROM loans WHERE disbursed_on BETWEEN ? AND ?');
        $fees->execute([$from, $to]);
        return [
            'interest' => Money::toDecimal(Money::fromDecimal($parts['interest'] ?? null)),
            'penalties' => Money::toDecimal(Money::fromDecimal($parts['penalty'] ?? null) + Money::fromDecimal($parts['fee'] ?? null)),
            'processing_fees' => Money::toDecimal(Money::fromDecimal($fees->fetchColumn())),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        $from = $request->string('from') ?: date('Y-m-01');
        $to = $request->string('to') ?: date('Y-m-d');
        foreach ([$from, $to] as $d) {
            $p = \DateTime::createFromFormat('!Y-m-d', $d);
            if (!$p || $p->format('Y-m-d') !== $d) {
                Response::error('Enter valid dates.', 422);
            }
        }
        return [$from, $to];
    }
}
