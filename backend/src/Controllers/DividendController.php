<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\LoanMath;
use Sacco\Support\Notify;
use Sacco\Support\Audit;
use Sacco\Support\Ledger;
use Sacco\Support\LedgerException;
use Sacco\Support\Money;

/**
 * Dividends on share capital. The admin gives a percentage and a record date; each member's share
 * balance at the end of that day earns the percentage, rounded down to whole shillings, and it is
 * credited to their shares account. Preview first, post, and reverse a run as a whole if needed.
 */
final class DividendController
{
    /** POST /api/dividends/preview { rate_percent, record_date } */
    public function preview(Request $request): never
    {
        [$rate, $recordDate] = $this->inputs($request);
        Response::ok($this->calculate($rate, $recordDate));
    }

    /** POST /api/dividends { label, rate_percent, record_date, value_date } */
    public function post(Request $request): never
    {
        [$rate, $recordDate] = $this->inputs($request);
        $label = mb_substr($request->string('label'), 0, 80);
        if ($label === '') {
            Response::error('Name this dividend, e.g. "2026".', 422, ['label' => 'Required']);
        }
        $valueDate = $this->date($request->string('value_date') ?: date('Y-m-d'), 'value_date');
        if ($valueDate < $recordDate) {
            Response::error('The dividend can\'t be credited before its record date.', 422, ['value_date' => 'Too early']);
        }
        $db = Database::tenant();
        $dup = $db->prepare("SELECT 1 FROM dividend_runs WHERE label = ? AND status = 'posted'");
        $dup->execute([$label]);
        if ($dup->fetchColumn()) {
            Response::error("A dividend called \"$label\" has already been posted.", 422, ['label' => 'Duplicate']);
        }

        $runId = Database::transaction(function (\PDO $db) use ($rate, $recordDate, $valueDate, $label) {
            $calc = $this->calculate($rate, $recordDate);
            if (!$calc['member_count']) {
                throw new LedgerException('No member had share capital on that date, so there is nothing to pay.');
            }
            $db->prepare("INSERT INTO dividend_runs (kind, label, year, rate_percent, credit_account, record_date, value_date, member_count, total_shares, total_dividend, created_by) VALUES ('dividend', ?, ?, ?, 'shares', ?, ?, ?, ?, ?, ?)")
                ->execute([$label, (int) substr($recordDate, 0, 4), $rate, $recordDate, $valueDate, $calc['member_count'], $calc['total_shares'], $calc['total_dividend'], Auth::id()]);
            $runId = (int) $db->lastInsertId();
            foreach ($calc['rows'] as $row) {
                $cents = Money::fromDecimal($row['dividend']);
                if ($cents > 0) {
                    Ledger::post($db, $row['user_id'], 'shares', 'dividend', $cents, [
                        'value_date' => $valueDate,
                        'reference' => "Dividend $label",
                        'narration' => "$rate% on " . number_format(Money::fromDecimal($row['shares']) / 100) . ' shares as at ' . date('j M Y', strtotime($recordDate)),
                        'dividend_run_id' => $runId,
                    ]);
                }
            }
            return $runId;
        });
        Audit::log('dividend.posted', 'dividend_run', $runId, ['label' => $label, 'rate' => $rate]);
        $paid = Database::tenant()->prepare("SELECT user_id, amount FROM transactions WHERE dividend_run_id = ? AND type = 'dividend'");
        $paid->execute([$runId]);
        foreach ($paid->fetchAll() as $p) {
            Notify::member((int) $p['user_id'], 'dividend', "Your $label dividend: " . Notify::money($p['amount']),
                Notify::money($p['amount']) . " ($rate% of your share capital) has been added to your shares.", '/statement');
        }

        Response::ok(['run' => $this->run($runId)], 201);
    }

    /** GET /api/dividends */
    public function index(Request $request): never
    {
        $ids = Database::tenant()->query('SELECT id FROM dividend_runs ORDER BY id DESC')->fetchAll(\PDO::FETCH_COLUMN);
        Response::ok(['runs' => array_map(fn ($id) => $this->run((int) $id), $ids)]);
    }

    /** GET /api/dividends/{id} - the run and what each member got */
    public function show(Request $request): never
    {
        $id = (int) $request->param('id');
        $run = $this->run($id);
        $stmt = Database::tenant()->prepare(
            "SELECT u.full_name, u.account_no, t.amount, t.narration
             FROM transactions t JOIN users u ON u.id = t.user_id
             WHERE t.dividend_run_id = ? AND t.type IN ('dividend', 'profit') ORDER BY u.full_name"
        );
        $stmt->execute([$id]);
        Response::ok(['run' => $run, 'rows' => $stmt->fetchAll()]);
    }

    /** POST /api/dividends/{id}/reverse { reason } */
    public function reverse(Request $request): never
    {
        $id = (int) $request->param('id');
        $run = $this->run($id);
        if ($run['status'] !== 'posted') {
            Response::error('This payout has already been reversed.', 422);
        }
        $reason = mb_substr($request->string('reason'), 0, 255);
        if ($reason === '') {
            Response::error('Say why this dividend is being reversed.', 422, ['reason' => 'Required']);
        }
        Database::transaction(function (\PDO $db) use ($id, $reason) {
            $db->prepare('SELECT id FROM dividend_runs WHERE id = ? FOR UPDATE')->execute([$id]);
            $stmt = $db->prepare(
                "SELECT t.id, u.full_name FROM transactions t JOIN users u ON u.id = t.user_id
                 WHERE t.dividend_run_id = ? AND t.type IN ('dividend', 'profit') AND NOT EXISTS (SELECT 1 FROM transactions r WHERE r.reversal_of = t.id)"
            );
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll() as $t) {
                try {
                    Ledger::reverse($db, (int) $t['id'], "Dividend reversed: $reason", null, $id);
                } catch (LedgerException $e) {
                    throw new LedgerException("Can't reverse {$t['full_name']}'s dividend: " . $e->getMessage());
                }
            }
            $db->prepare("UPDATE dividend_runs SET status = 'reversed', reversed_by = ?, reversed_at = NOW(), reversal_reason = ? WHERE id = ?")
                ->execute([Auth::id(), $reason, $id]);
        });
        Audit::log('dividend.reversed', 'dividend_run', $id, ['reason' => $reason]);
        $who = Database::tenant()->prepare("SELECT DISTINCT user_id FROM transactions WHERE dividend_run_id = ? AND type = 'reversal'");
        $who->execute([$id]);
        $what = ($run['kind'] ?? 'dividend') === 'profit' ? 'profit share' : 'dividend';
        foreach ($who->fetchAll(\PDO::FETCH_COLUMN) as $userId) {
            Notify::member((int) $userId, 'money_reversed', "Your {$run['label']} $what was reversed",
                "The SACCO office reversed the {$run['label']} $what: $reason. It has been taken back off your account.", '/statement');
        }
        Response::ok(['run' => $this->run($id)]);
    }

    // ----------------------------------------------------------------------

    /** @return array{0: string, 1: string} rate as "8.5", record date */
    private function inputs(Request $request): array
    {
        $cents = Money::parse($request->input('rate_percent'));
        if ($cents === null || $cents <= 0 || $cents > 100_00) {
            Response::error('Enter the dividend rate, a percentage between 0 and 100.', 422, ['rate_percent' => 'Invalid']);
        }
        $rate = rtrim(rtrim(Money::toDecimal($cents), '0'), '.');
        return [$rate, $this->date($request->string('record_date'), 'record_date')];
    }

    private function date(string $value, string $field): string
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value || $value > date('Y-m-d')) {
            Response::error('Enter a valid date, not in the future.', 422, [$field => 'Invalid']);
        }
        return $value;
    }

    /** Every member's share capital at the end of $recordDate and their dividend on it */
    private function calculate(string $rate, string $recordDate): array
    {
        $stmt = Database::tenant()->prepare(
            "SELECT u.id, u.full_name, u.account_no, u.status, SUM(t.amount) AS shares
             FROM transactions t JOIN users u ON u.id = t.user_id
             WHERE t.account = 'shares' AND t.value_date <= ?
             GROUP BY u.id HAVING shares > 0 ORDER BY u.full_name"
        );
        $stmt->execute([$recordDate]);
        $hundredths = (int) Money::parse($rate); // "8.5" -> 850: hundredths of a percent

        $rows = [];
        $totalShares = 0;
        $totalDividend = 0;
        foreach ($stmt->fetchAll() as $m) {
            $shares = Money::fromDecimal($m['shares']);
            // Float only for the ratio; rounded down to whole shillings
            $dividend = (int) (floor($shares * ($hundredths / 10000) / LoanMath::UNIT) * LoanMath::UNIT);
            $totalShares += $shares;
            $totalDividend += $dividend;
            $rows[] = [
                'user_id' => (int) $m['id'],
                'full_name' => $m['full_name'],
                'account_no' => $m['account_no'],
                'status' => $m['status'],
                'shares' => Money::toDecimal($shares),
                'dividend' => Money::toDecimal($dividend),
            ];
        }
        return [
            'rate_percent' => $rate,
            'record_date' => $recordDate,
            'rows' => $rows,
            'member_count' => count($rows),
            'total_shares' => Money::toDecimal($totalShares),
            'total_dividend' => Money::toDecimal($totalDividend),
        ];
    }

    private function run(int $id): array
    {
        $stmt = Database::tenant()->prepare(
            'SELECT d.*, c.full_name AS created_by_name, r.full_name AS reversed_by_name
             FROM dividend_runs d LEFT JOIN users c ON c.id = d.created_by LEFT JOIN users r ON r.id = d.reversed_by WHERE d.id = ?'
        );
        $stmt->execute([$id]);
        $run = $stmt->fetch() ?: Response::error('Dividend not found.', 404);
        $run['id'] = (int) $run['id'];
        $run['member_count'] = (int) $run['member_count'];
        $run['rate_percent'] = rtrim(rtrim($run['rate_percent'], '0'), '.'); // "8.500" -> "8.5"
        $run['default_rate'] = \Sacco\Support\Earnings::dividendRate();
        return $run;
    }
}
