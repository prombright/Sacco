<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Support\Earnings;
use Sacco\Support\Ledger;
use Sacco\Support\LedgerException;
use Sacco\Support\Money;
use Sacco\Support\Notify;
use Sacco\Support\Settings;

/**
 * The SACCO's investments and sharing their profit with members. Admins see everything; the super
 * admin records investments, the profit they bring, sales, and pays out the year's profit share.
 */
final class InvestmentController
{
    /** GET /api/investments?year= - the investments and the year's figures */
    public function index(Request $request): never
    {
        $db = Database::tenant();
        $year = $this->year($request);
        $rows = $db->query(
            'SELECT i.*, (SELECT COALESCE(SUM(r.amount), 0) FROM investment_returns r WHERE r.investment_id = i.id) AS profit_to_date
             FROM investments i ORDER BY i.status, i.started_on DESC, i.id DESC'
        )->fetchAll();
        $returns = $db->query(
            'SELECT r.*, i.name AS investment_name, u.full_name AS created_by_name, (SELECT x.id FROM investment_returns x WHERE x.reversal_of = r.id) AS reversed_by
             FROM investment_returns r JOIN investments i ON i.id = r.investment_id LEFT JOIN users u ON u.id = r.created_by
             ORDER BY r.received_on DESC, r.id DESC LIMIT 100'
        )->fetchAll();

        $y = Earnings::investmentYear($db, $year);
        $posted = $db->prepare("SELECT id, label, pool, total_dividend, member_count, created_at FROM dividend_runs WHERE kind = 'profit' AND year = ? AND status = 'posted'");
        $posted->execute([$year]);

        Response::ok([
            'year' => $year,
            'kinds' => Earnings::INVESTMENT_KINDS,
            'investments' => array_map(fn ($i) => $i + [
                'expected_this_year' => Money::toDecimal(Earnings::expectedFor($i, $year)),
                'kind_label' => Earnings::INVESTMENT_KINDS[$i['kind']] ?? $i['kind'],
            ], $rows),
            'returns' => $returns,
            'summary' => array_map([Money::class, 'toDecimal'], $y),
            'profit_share_posted' => $posted->fetch() ?: null,
            'basis' => Settings::get('profit_basis', 'average_savings'),
            'credit_account' => Settings::get('profit_credit_account', 'savings'),
        ]);
    }

    /** POST /api/investments */
    public function store(Request $request): never
    {
        $d = $this->validated($request);
        $db = Database::tenant();
        $db->prepare('INSERT INTO investments (name, kind, institution, amount, started_on, expected_return_percent, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$d['name'], $d['kind'], $d['institution'], $d['amount'], $d['started_on'], $d['expected_return_percent'], $d['notes'], Auth::id()]);
        $id = (int) $db->lastInsertId();
        Audit::log('investment.created', 'investment', $id, ['name' => $d['name'], 'amount' => $d['amount']]);
        Response::ok(['id' => $id], 201);
    }

    /** PATCH /api/investments/{id} */
    public function update(Request $request): never
    {
        $inv = $this->find((int) $request->param('id'));
        $d = $this->validated($request);
        Database::tenant()->prepare('UPDATE investments SET name = ?, kind = ?, institution = ?, amount = ?, started_on = ?, expected_return_percent = ?, notes = ? WHERE id = ?')
            ->execute([$d['name'], $d['kind'], $d['institution'], $d['amount'], $d['started_on'], $d['expected_return_percent'], $d['notes'], $inv['id']]);
        Audit::log('investment.updated', 'investment', (int) $inv['id'], ['before' => $inv, 'after' => $d]);
        Response::ok();
    }

    /** POST /api/investments/{id}/returns { amount, received_on, note } - profit it brought (interest, distribution) */
    public function addReturn(Request $request): never
    {
        $inv = $this->find((int) $request->param('id'));
        $cents = Money::parse($request->input('amount'));
        if ($cents === null || $cents <= 0) {
            Response::error('Enter the profit received.', 422, ['amount' => 'Required']);
        }
        $date = $this->date($request->string('received_on') ?: date('Y-m-d'));
        if ($date < $inv['started_on']) {
            Response::error('Profit can\'t be received before the investment started.', 422);
        }
        $db = Database::tenant();
        $db->prepare("INSERT INTO investment_returns (investment_id, kind, amount, received_on, note, created_by) VALUES (?, 'income', ?, ?, ?, ?)")
            ->execute([$inv['id'], Money::toDecimal($cents), $date, mb_substr($request->string('note'), 0, 255) ?: null, Auth::id()]);
        Audit::log('investment.return', 'investment', (int) $inv['id'], ['amount' => Money::toDecimal($cents)]);
        Response::ok(null, 201);
    }

    /**
     * POST /api/investments/{id}/close { closing_value, closed_on } - sold or cashed in. The
     * difference from what was invested is recorded as the gain (or loss) on it.
     */
    public function close(Request $request): never
    {
        $inv = $this->find((int) $request->param('id'));
        if ($inv['status'] !== 'active') {
            Response::error('This investment is already closed.', 422);
        }
        $value = Money::parse($request->input('closing_value'));
        if ($value === null || $value < 0) {
            Response::error('Enter what it was sold or cashed in for.', 422, ['closing_value' => 'Required']);
        }
        $date = $this->date($request->string('closed_on') ?: date('Y-m-d'));
        $gain = $value - Money::fromDecimal($inv['amount']);
        Database::transaction(function (\PDO $db) use ($inv, $value, $date, $gain) {
            $db->prepare("UPDATE investments SET status = 'closed', closed_on = ?, closing_value = ? WHERE id = ?")->execute([$date, Money::toDecimal($value), $inv['id']]);
            if ($gain !== 0) {
                $db->prepare("INSERT INTO investment_returns (investment_id, kind, amount, received_on, note, created_by) VALUES (?, 'gain', ?, ?, ?, ?)")
                    ->execute([$inv['id'], Money::toDecimal($gain), $date, ($gain > 0 ? 'Gain' : 'Loss') . ' on sale: sold for ' . number_format($value / 100) . ', cost ' . number_format(Money::fromDecimal($inv['amount']) / 100), Auth::id()]);
            }
        });
        Audit::log('investment.closed', 'investment', (int) $inv['id'], ['value' => Money::toDecimal($value), 'gain' => Money::toDecimal($gain)]);
        Response::ok();
    }

    /** POST /api/investment-returns/{id}/reverse { reason } */
    public function reverseReturn(Request $request): never
    {
        $db = Database::tenant();
        $stmt = $db->prepare('SELECT r.*, (SELECT x.id FROM investment_returns x WHERE x.reversal_of = r.id) AS reversed_by FROM investment_returns r WHERE r.id = ?');
        $stmt->execute([(int) $request->param('id')]);
        $r = $stmt->fetch() ?: Response::error('Entry not found.', 404);
        if ($r['reversal_of'] || $r['reversed_by']) {
            Response::error('This entry has already been reversed.', 422);
        }
        $reason = mb_substr($request->string('reason'), 0, 200);
        if ($reason === '') {
            Response::error('Say why it is being reversed.', 422);
        }
        $db->prepare('INSERT INTO investment_returns (investment_id, kind, amount, received_on, note, reversal_of, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$r['investment_id'], $r['kind'], Money::toDecimal(-Money::fromDecimal($r['amount'])), $r['received_on'], "Reversed: $reason", $r['id'], Auth::id()]);
        Audit::log('investment.return_reversed', 'investment', (int) $r['investment_id'], ['reason' => $reason]);
        Response::ok();
    }

    /** POST /api/profit-share/preview { year, pool? } - each member's share, before paying */
    public function previewShare(Request $request): never
    {
        $year = $this->year($request);
        Response::ok($this->share(Database::tenant(), $year, $this->pool($request, $year)));
    }

    /** POST /api/profit-share { year, pool?, value_date? } - credits every member their share */
    public function postShare(Request $request): never
    {
        $year = $this->year($request);
        $valueDate = $this->date($request->string('value_date') ?: min(date('Y-m-d'), "$year-12-31"));
        $db = Database::tenant();
        $dup = $db->prepare("SELECT 1 FROM dividend_runs WHERE kind = 'profit' AND year = ? AND status = 'posted'");
        $dup->execute([$year]);
        if ($dup->fetchColumn()) {
            Response::error("The $year profit share has already been paid. Reverse it first to pay it again.", 422);
        }
        $pool = $this->pool($request, $year);

        $runId = Database::transaction(function (\PDO $db) use ($year, $pool, $valueDate) {
            $share = $this->share($db, $year, $pool);
            if (!$share['member_count']) {
                throw new LedgerException('No member had savings that year, or there is no profit to share.');
            }
            $account = Settings::get('profit_credit_account', 'savings');
            $db->prepare(
                "INSERT INTO dividend_runs (kind, label, year, rate_percent, pool, basis, credit_account, record_date, value_date, member_count, total_shares, total_dividend, created_by)
                 VALUES ('profit', ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                "Profit share $year", $year, Money::toDecimal($pool), $share['basis'], $account, "$year-12-31", $valueDate,
                $share['member_count'], $share['total_basis'], $share['total_paid'], Auth::id(),
            ]);
            $runId = (int) $db->lastInsertId();
            foreach ($share['rows'] as $row) {
                if (Money::fromDecimal($row['share']) > 0) {
                    Ledger::post($db, $row['user_id'], $account, 'profit', Money::fromDecimal($row['share']), [
                        'value_date' => $valueDate,
                        'reference' => "Profit share $year",
                        'narration' => $row['percent'] . '% of the SACCO\'s ' . number_format($pool / 100) . ' investment profit',
                        'dividend_run_id' => $runId,
                    ]);
                }
            }
            return $runId;
        });
        foreach ($this->share($db, $year, $pool)['rows'] as $row) {
            Notify::member($row['user_id'], 'profit_paid', "Your $year profit share", 'Your share of the SACCO\'s investment profit for ' . $year . ', ' . Notify::money($row['share']) . ', has been added to your ' . Settings::get('profit_credit_account', 'savings') . '.', '/');
        }
        Audit::log('profit_share.posted', 'dividend_run', $runId, ['year' => $year, 'pool' => Money::toDecimal($pool)]);
        Response::ok(['run_id' => $runId], 201);
    }

    /** GET /api/me/earnings - what this member can expect this year (dividend and profit share) */
    public function myEarnings(Request $request): never
    {
        Response::ok(Earnings::forMember(Database::tenant(), (int) Auth::id()));
    }

    // ----------------------------------------------------------------------

    /** The pool given, else the profit actually received in the year */
    private function pool(Request $request, int $year): int
    {
        $given = $request->input('pool');
        if ($given !== null && $given !== '') {
            $cents = Money::parse($given);
            if ($cents === null || $cents < 0) {
                Response::error('Enter the profit to share.', 422, ['pool' => 'Invalid']);
            }
            return $cents;
        }
        return max(0, Earnings::investmentYear(Database::tenant(), $year)['actual_profit']);
    }

    private function share(\PDO $db, int $year, int $pool): array
    {
        $basis = Earnings::profitBasis($db, $year);
        $split = Earnings::split($pool, $basis);
        $total = array_sum($basis);
        $names = $db->query("SELECT id, full_name, account_no FROM users WHERE role = 'member'")->fetchAll(\PDO::FETCH_UNIQUE);
        $rows = [];
        foreach ($basis as $id => $b) {
            $rows[] = [
                'user_id' => $id,
                'full_name' => $names[$id]['full_name'] ?? '',
                'account_no' => $names[$id]['account_no'] ?? '',
                'basis' => Money::toDecimal($b),
                'percent' => $total ? round($b / $total * 100, 2) : 0,
                'share' => Money::toDecimal($split[$id] ?? 0),
            ];
        }
        usort($rows, fn ($a, $b) => Money::fromDecimal($b['share']) <=> Money::fromDecimal($a['share']));
        return [
            'year' => $year,
            'pool' => Money::toDecimal($pool),
            'basis' => Settings::get('profit_basis', 'average_savings'),
            'rows' => $rows,
            'member_count' => count(array_filter($split)),
            'total_basis' => Money::toDecimal($total),
            'total_paid' => Money::toDecimal(array_sum($split)),
        ];
    }

    private function find(int $id): array
    {
        $stmt = Database::tenant()->prepare('SELECT * FROM investments WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: Response::error('Investment not found.', 404);
    }

    private function validated(Request $request): array
    {
        $name = mb_substr($request->string('name'), 0, 120);
        if ($name === '') {
            Response::error('Name the investment, e.g. "UAP unit trust".', 422, ['name' => 'Required']);
        }
        $kind = $request->string('kind');
        if (!isset(Earnings::INVESTMENT_KINDS[$kind])) {
            Response::error('Choose the type of investment.', 422, ['kind' => 'Required']);
        }
        $amount = Money::parse($request->input('amount'));
        if ($amount === null || $amount <= 0) {
            Response::error('Enter the amount invested.', 422, ['amount' => 'Required']);
        }
        $rate = Money::parse($request->input('expected_return_percent', '0') ?: '0');
        if ($rate === null || $rate < 0 || $rate > 100_00) {
            Response::error('Expected return between 0% and 100% a year.', 422, ['expected_return_percent' => 'Invalid']);
        }
        return [
            'name' => $name,
            'kind' => $kind,
            'institution' => mb_substr($request->string('institution'), 0, 120) ?: null,
            'amount' => Money::toDecimal($amount),
            'started_on' => $this->date($request->string('started_on') ?: date('Y-m-d')),
            'expected_return_percent' => Money::toDecimal($rate),
            'notes' => mb_substr($request->string('notes'), 0, 255) ?: null,
        ];
    }

    private function year(Request $request): int
    {
        $y = (int) ($request->input('year') ?: date('Y'));
        if ($y < 2000 || $y > (int) date('Y')) {
            Response::error('Choose a year up to this one.', 422);
        }
        return $y;
    }

    private function date(string $value): string
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value || $value > date('Y-m-d')) {
            Response::error('Enter a valid date, not in the future.', 422);
        }
        return $value;
    }
}
