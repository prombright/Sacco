<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Support\Periods;
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
 * The two fees members pay: the membership fee, once, and the subscription, every year. Each is
 * charged to the active members who owe it, then taken from savings, paid in cash, or waived.
 */
final class MembershipFeeController
{
    private const SETTING = ['subscription' => 'subscription_fee_annual', 'membership' => 'membership_fee_once'];

    /** GET /api/membership-fees?kind=&year= */
    public function index(Request $request): never
    {
        $kind = $this->kind($request);
        $year = $this->year($request);
        $db = Database::tenant();
        [$scope, $args] = $kind === 'membership' ? ["f.kind = 'membership'", []] : ["f.kind = 'subscription' AND f.year = ?", [$year]];
        $stmt = $db->prepare(
            "SELECT f.*, u.full_name, u.account_no, u.employee_no, p.full_name AS paid_by_name
             FROM membership_fees f JOIN users u ON u.id = f.user_id LEFT JOIN users p ON p.id = f.paid_by
             WHERE $scope ORDER BY FIELD(f.status, 'unpaid', 'paid', 'waived'), u.full_name"
        );
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        $sum = ['charged' => 0, 'paid' => 0, 'unpaid' => 0, 'waived' => 0];
        $count = ['paid' => 0, 'unpaid' => 0, 'waived' => 0];
        foreach ($rows as $r) {
            $c = Money::fromDecimal($r['amount']);
            $sum['charged'] += $c;
            $sum[$r['status']] += $c;
            $count[$r['status']]++;
        }
        Response::ok([
            'year' => $year,
            'kind' => $kind,
            'fee' => Settings::get(self::SETTING[$kind], '0'),
            'fees' => $rows,
            'totals' => array_map([Money::class, 'toDecimal'], $sum),
            'counts' => $count,
            'not_charged' => count($this->notCharged($db, $kind, $year)),
        ]);
    }

    /** POST /api/membership-fees/charge { kind, year } - every active member who hasn't been charged it */
    public function charge(Request $request): never
    {
        $kind = $this->kind($request);
        $year = $this->year($request);
        $fee = Money::parse(Settings::get(self::SETTING[$kind], '0')) ?? 0;
        if ($fee <= 0) {
            Response::error('Set this fee on the Setup page first.', 422);
        }
        $db = Database::tenant();
        $ids = $this->notCharged($db, $kind, $year);
        $insert = $db->prepare('INSERT INTO membership_fees (user_id, kind, year, amount, created_by) VALUES (?, ?, ?, ?, ?)');
        foreach ($ids as $id) {
            $insert->execute([$id, $kind, $year, Money::toDecimal($fee), Auth::id()]);
        }
        if ($ids) {
            $name = self::name($kind, $year);
            Audit::log('membership_fee.charged', null, null, ['year' => $year, 'kind' => $kind, 'count' => count($ids), 'amount' => Money::toDecimal($fee)]);
            foreach ($ids as $id) {
                Notify::member((int) $id, 'account_update', 'Your ' . $name, 'Your ' . $name . ' of ' . Notify::cents($fee) . ' is due. The office can take it from your savings, or you can pay it at the office.', '/');
            }
        }
        Response::ok(['charged' => count($ids)]);
    }

    /** POST /api/membership-fees/collect { kind, year, ids? } - takes unpaid fees from members' savings */
    public function collect(Request $request): never
    {
        $kind = $this->kind($request);
        $year = $this->year($request);
        $only = array_filter(array_map('intval', (array) $request->input('ids', [])));
        $db = Database::tenant();
        [$scope, $args] = $kind === 'membership' ? ["f.kind = 'membership'", []] : ["f.kind = 'subscription' AND f.year = ?", [$year]];
        $stmt = $db->prepare("SELECT f.*, u.full_name FROM membership_fees f JOIN users u ON u.id = f.user_id WHERE $scope AND f.status = 'unpaid'");
        $stmt->execute($args);
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
            $name = self::name($kind, (int) $f['year']);
            try {
                Database::transaction(function (\PDO $db) use ($f, $cents, $kind, $name) {
                    $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$f['user_id']]);
                    if (Savings::withdrawable($db, (int) $f['user_id']) < $cents) {
                        throw new LedgerException('Not enough free savings');
                    }
                    $tx = Ledger::post($db, (int) $f['user_id'], 'savings', $kind === 'membership' ? 'membership_fee' : 'subscription', -$cents, [
                        'value_date' => date('Y-m-d'),
                        'reference' => ucfirst($name),
                        'narration' => ucfirst($name),
                    ]);
                    $db->prepare("UPDATE membership_fees SET status = 'paid', method = 'savings', paid_on = CURDATE(), transaction_id = ?, paid_by = ? WHERE id = ?")
                        ->execute([$tx['id'], Auth::id(), $f['id']]);
                });
                $collected[] = $f;
            } catch (LedgerException) {
                $short[] = $f['full_name'];
            }
        }
        if ($collected) {
            Audit::log('membership_fee.collected', null, null, ['year' => $year, 'kind' => $kind, 'count' => count($collected)]);
            foreach ($collected as $f) {
                $name = self::name($kind, (int) $f['year']);
                Notify::member((int) $f['user_id'], 'money_out', ucfirst($name) . ' paid from savings', Notify::money($f['amount']) . " was taken from your savings for your $name.", '/statement');
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
        Periods::check($date);
        Database::tenant()->prepare("UPDATE membership_fees SET status = 'paid', method = 'cash', money_account_id = ?, paid_on = ?, reference = ?, paid_by = ? WHERE id = ?")
            ->execute([Periods::moneyAccount($request), $date, mb_substr($request->string('reference'), 0, 80) ?: null, Auth::id(), $f['id']]);
        Audit::log('membership_fee.paid', 'membership_fee', (int) $f['id'], ['member' => (int) $f['user_id'], 'amount' => $f['amount']]);
        $name = self::name($f['kind'], (int) $f['year']);
        Notify::member((int) $f['user_id'], 'money_in', ucfirst($name) . ' received', 'Thank you: your ' . Notify::money($f['amount']) . " $name has been received.", '/');
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
                Ledger::reverse($db, (int) $f['transaction_id'], "Fee undone: $reason");
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
        $stmt = Database::tenant()->prepare('SELECT kind, year, amount, status, method, paid_on FROM membership_fees WHERE user_id = ? ORDER BY year DESC');
        $stmt->execute([Auth::id()]);
        Response::ok(['fees' => $stmt->fetchAll(), 'membership_fee' => Settings::get(self::SETTING['membership'], '0'), 'subscription_fee' => Settings::get(self::SETTING['subscription'], '0')]);
    }

    /** @return int[] active members who haven't been charged: the membership fee ever, the subscription this year */
    private function notCharged(\PDO $db, string $kind, int $year): array
    {
        $owed = $kind === 'membership' ? "f.kind = 'membership'" : "f.kind = 'subscription' AND f.year = $year";
        return array_map('intval', $db->query("SELECT u.id FROM users u WHERE u.role = 'member' AND u.status = 'active' AND NOT EXISTS (SELECT 1 FROM membership_fees f WHERE f.user_id = u.id AND $owed)")->fetchAll(\PDO::FETCH_COLUMN));
    }

    private static function name(string $kind, int $year): string
    {
        return $kind === 'membership' ? 'membership fee' : "$year subscription";
    }

    private function kind(Request $request): string
    {
        $kind = (string) ($request->input('kind') ?: $request->query('kind', 'subscription'));
        in_array($kind, ['subscription', 'membership'], true) || Response::error('Choose a fee.', 422);
        return $kind;
    }

    private function find(int $id): array
    {
        $stmt = Database::tenant()->prepare('SELECT * FROM membership_fees WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: Response::error('Fee not found.', 404);
    }

    private function year(Request $request): int
    {
        $year = (int) ($request->input('year') ?: $request->query('year') ?: date('Y'));
        if ($year < 2000 || $year > (int) date('Y') + 1) {
            Response::error('Choose a year.', 422);
        }
        return $year;
    }
}
