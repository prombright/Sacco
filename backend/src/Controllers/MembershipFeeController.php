<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Support\Ledger;
use Sacco\Support\LedgerException;
use Sacco\Support\Money;
use Sacco\Support\Notify;
use Sacco\Support\Savings;
use Sacco\Support\Settings;

/**
 * The yearly membership fee: charged to every active member, then taken from savings, paid in
 * cash, or waived. Members joining later in the year are charged when the office charges again.
 */
final class MembershipFeeController
{
    /** GET /api/membership-fees?year= */
    public function index(Request $request): never
    {
        $year = $this->year($request);
        $db = Database::tenant();
        $stmt = $db->prepare(
            "SELECT f.*, u.full_name, u.account_no, u.employee_no, p.full_name AS paid_by_name
             FROM membership_fees f JOIN users u ON u.id = f.user_id LEFT JOIN users p ON p.id = f.paid_by
             WHERE f.year = ? ORDER BY FIELD(f.status, 'unpaid', 'paid', 'waived'), u.full_name"
        );
        $stmt->execute([$year]);
        $rows = $stmt->fetchAll();
        $sum = ['charged' => 0, 'paid' => 0, 'unpaid' => 0, 'waived' => 0];
        $count = ['paid' => 0, 'unpaid' => 0, 'waived' => 0];
        foreach ($rows as $r) {
            $c = Money::fromDecimal($r['amount']);
            $sum['charged'] += $c;
            $sum[$r['status']] += $c;
            $count[$r['status']]++;
        }
        $notCharged = $db->prepare("SELECT COUNT(*) FROM users u WHERE u.role = 'member' AND u.status = 'active' AND NOT EXISTS (SELECT 1 FROM membership_fees f WHERE f.user_id = u.id AND f.year = ?)");
        $notCharged->execute([$year]);
        Response::ok([
            'year' => $year,
            'fee' => Settings::get('membership_fee_annual', '0'),
            'fees' => $rows,
            'totals' => array_map([Money::class, 'toDecimal'], $sum),
            'counts' => $count,
            'not_charged' => (int) $notCharged->fetchColumn(),
        ]);
    }

    /** POST /api/membership-fees/charge { year } - every active member not yet charged for the year */
    public function charge(Request $request): never
    {
        $year = $this->year($request);
        $fee = Money::parse(Settings::get('membership_fee_annual', '0')) ?? 0;
        if ($fee <= 0) {
            Response::error('Set the yearly membership fee on the Setup page first.', 422);
        }
        $db = Database::tenant();
        $members = $db->prepare("SELECT id FROM users u WHERE u.role = 'member' AND u.status = 'active' AND NOT EXISTS (SELECT 1 FROM membership_fees f WHERE f.user_id = u.id AND f.year = ?)");
        $members->execute([$year]);
        $ids = $members->fetchAll(\PDO::FETCH_COLUMN);
        $insert = $db->prepare('INSERT INTO membership_fees (user_id, year, amount, created_by) VALUES (?, ?, ?, ?)');
        foreach ($ids as $id) {
            $insert->execute([$id, $year, Money::toDecimal($fee), Auth::id()]);
        }
        if ($ids) {
            Audit::log('membership_fee.charged', null, null, ['year' => $year, 'count' => count($ids), 'amount' => Money::toDecimal($fee)]);
            foreach ($ids as $id) {
                Notify::member((int) $id, 'account_update', "Your $year membership fee", 'The yearly membership fee of ' . Notify::cents($fee) . " for $year is due. The office can take it from your savings, or you can pay it at the office.", '/');
            }
        }
        Response::ok(['charged' => count($ids)]);
    }

    /** POST /api/membership-fees/collect { year, ids? } - takes unpaid fees from members' savings */
    public function collect(Request $request): never
    {
        $year = $this->year($request);
        $only = array_filter(array_map('intval', (array) $request->input('ids', [])));
        $db = Database::tenant();
        $stmt = $db->prepare("SELECT f.*, u.full_name FROM membership_fees f JOIN users u ON u.id = f.user_id WHERE f.year = ? AND f.status = 'unpaid'");
        $stmt->execute([$year]);
        $collected = [];
        $short = [];
        foreach ($stmt->fetchAll() as $f) {
            if ($only && !in_array((int) $f['id'], $only, true)) {
                continue;
            }
            if ((int) $f['user_id'] === Auth::id()) {
                continue;
            }
            $cents = Money::fromDecimal($f['amount']);
            try {
                Database::transaction(function (\PDO $db) use ($f, $cents, $year) {
                    $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$f['user_id']]);
                    if (Savings::withdrawable($db, (int) $f['user_id']) < $cents) {
                        throw new LedgerException('Not enough free savings');
                    }
                    $tx = Ledger::post($db, (int) $f['user_id'], 'savings', 'membership_fee', -$cents, [
                        'value_date' => date('Y-m-d'),
                        'reference' => "Membership fee $year",
                        'narration' => "Yearly membership fee for $year",
                    ]);
                    $db->prepare("UPDATE membership_fees SET status = 'paid', method = 'savings', paid_on = CURDATE(), transaction_id = ?, paid_by = ? WHERE id = ? AND status = 'unpaid'")
                        ->execute([$tx['id'], Auth::id(), $f['id']]);
                });
                $collected[] = $f;
            } catch (LedgerException) {
                $short[] = $f['full_name'];
            }
        }
        if ($collected) {
            Audit::log('membership_fee.collected', null, null, ['year' => $year, 'count' => count($collected)]);
            foreach ($collected as $f) {
                Notify::member((int) $f['user_id'], 'money_out', "$year membership fee paid from savings", Notify::money($f['amount']) . " was taken from your savings for the $year membership fee.", '/statement');
            }
        }
        Response::ok(['collected' => count($collected), 'not_enough_savings' => $short]);
    }

    /** POST /api/membership-fees/{id}/pay { paid_on?, reference? } - paid in cash or by bank slip */
    public function pay(Request $request): never
    {
        $f = $this->find((int) $request->param('id'));
        if ($f['status'] !== 'unpaid') {
            Response::error('This fee is already ' . $f['status'] . '.', 422);
        }
        $date = $request->string('paid_on') ?: date('Y-m-d');
        $d = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            Response::error('Enter a valid date, not in the future.', 422);
        }
        Database::tenant()->prepare("UPDATE membership_fees SET status = 'paid', method = 'cash', paid_on = ?, reference = ?, paid_by = ? WHERE id = ? AND status = 'unpaid'")
            ->execute([$date, mb_substr($request->string('reference'), 0, 80) ?: null, Auth::id(), $f['id']]);
        Audit::log('membership_fee.paid', 'membership_fee', (int) $f['id'], ['member' => (int) $f['user_id'], 'amount' => $f['amount']]);
        Notify::member((int) $f['user_id'], 'money_in', "{$f['year']} membership fee received", 'Thank you: your ' . Notify::money($f['amount']) . " membership fee for {$f['year']} has been received.", '/');
        Response::ok();
    }

    /** POST /api/membership-fees/{id}/waive { reason } */
    public function waive(Request $request): never
    {
        $f = $this->find((int) $request->param('id'));
        if ($f['status'] !== 'unpaid') {
            Response::error('Only an unpaid fee can be waived.', 422);
        }
        $reason = mb_substr($request->string('reason'), 0, 255);
        if ($reason === '') {
            Response::error('Say why the fee is waived.', 422, ['reason' => 'Required']);
        }
        Database::tenant()->prepare("UPDATE membership_fees SET status = 'waived', note = ?, paid_by = ? WHERE id = ?")->execute([$reason, Auth::id(), $f['id']]);
        Audit::log('membership_fee.waived', 'membership_fee', (int) $f['id'], ['member' => (int) $f['user_id'], 'reason' => $reason]);
        Response::ok();
    }

    /** POST /api/membership-fees/{id}/undo { reason } - a payment recorded by mistake goes back to unpaid */
    public function undo(Request $request): never
    {
        $f = $this->find((int) $request->param('id'));
        $reason = mb_substr($request->string('reason'), 0, 255);
        if ($reason === '') {
            Response::error('Say why.', 422, ['reason' => 'Required']);
        }
        if ($f['status'] === 'unpaid') {
            Response::error('This fee is not paid.', 422);
        }
        Database::transaction(function (\PDO $db) use ($f, $reason) {
            if ($f['method'] === 'savings' && $f['transaction_id']) {
                Ledger::reverse($db, (int) $f['transaction_id'], "Membership fee undone: $reason");
            }
            $db->prepare("UPDATE membership_fees SET status = 'unpaid', method = NULL, paid_on = NULL, reference = NULL, transaction_id = NULL, note = ? WHERE id = ?")
                ->execute(["Undone: $reason", $f['id']]);
        });
        Audit::log('membership_fee.undone', 'membership_fee', (int) $f['id'], ['member' => (int) $f['user_id'], 'reason' => $reason]);
        Response::ok();
    }

    /** GET /api/me/membership-fees */
    public function mine(Request $request): never
    {
        $stmt = Database::tenant()->prepare('SELECT year, amount, status, method, paid_on FROM membership_fees WHERE user_id = ? ORDER BY year DESC');
        $stmt->execute([Auth::id()]);
        Response::ok(['fees' => $stmt->fetchAll(), 'fee' => Settings::get('membership_fee_annual', '0')]);
    }

    private function find(int $id): array
    {
        $stmt = Database::tenant()->prepare('SELECT * FROM membership_fees WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: Response::error('Fee not found.', 404);
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
