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
use Sacco\Support\Periods;
use Sacco\Support\SavingsInterest;

/**
 * The savings interest held for members, and paying it out: the super admin chooses, member by
 * member or all at once, cash (it leaves the SACCO's money and is no longer held) or into their
 * savings (it becomes savings at once, and counts for loans and withdrawals from then).
 */
final class SavingsInterestController
{
    /** GET /api/savings-interest (office) - every member with interest held, and the latest payouts */
    public function index(Request $request): never
    {
        $db = Database::tenant();
        $members = $db->query(
            "SELECT u.id, u.full_name, u.account_no,
                    COALESCE((SELECT SUM(o.amount) FROM old_system_interest_payable o WHERE o.user_id = u.id), 0) AS from_old,
                    COALESCE((SELECT SUM(s.amount) FROM savings_interest s WHERE s.user_id = u.id), 0) AS earned,
                    COALESCE((SELECT SUM(p.amount) FROM savings_interest_payouts p WHERE p.user_id = u.id), 0) AS paid
             FROM users u WHERE u.role = 'member' ORDER BY u.full_name"
        )->fetchAll();
        $rows = [];
        $total = 0;
        foreach ($members as $m) {
            $held = Money::fromDecimal((string) $m['from_old']) + Money::fromDecimal((string) $m['earned']) - Money::fromDecimal((string) $m['paid']);
            if ($held <= 0) {
                continue;
            }
            $total += $held;
            $rows[] = ['id' => (int) $m['id'], 'full_name' => $m['full_name'], 'account_no' => $m['account_no'],
                'from_old' => $m['from_old'], 'earned' => $m['earned'], 'paid' => $m['paid'], 'held' => Money::toDecimal($held)];
        }
        $payouts = $db->query(
            "SELECT p.paid_on, p.method, p.amount, p.reference, u.full_name, u.account_no FROM savings_interest_payouts p JOIN users u ON u.id = p.user_id
             ORDER BY p.id DESC LIMIT 50"
        )->fetchAll();
        Response::ok([
            'rate_year' => round(SavingsInterest::yearlyPercent(), 3),
            'members' => $rows,
            'total_held' => Money::toDecimal($total),
            'payouts' => $payouts,
            'money_accounts' => Periods::moneyAccounts($db),
        ]);
    }

    /**
     * POST /api/savings-interest/pay { method: cash|savings, user_ids: int[] | "all", paid_on, reference?, money_account_id? }
     * (super admin) - pays each chosen member everything held for them
     */
    public function pay(Request $request): never
    {
        $method = $request->string('method');
        if (!in_array($method, ['cash', 'savings'], true)) {
            Response::error('Choose cash or into savings.', 422, ['method' => 'Required']);
        }
        $date = $request->string('paid_on') ?: date('Y-m-d');
        $d = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            Response::error('Enter a valid date, not in the future.', 422, ['paid_on' => 'Invalid']);
        }
        Periods::check($date);
        $reference = mb_substr($request->string('reference'), 0, 80) ?: null;
        $moneyAccount = $method === 'cash' ? Periods::moneyAccount($request) : null;
        $ids = $request->input('user_ids', []);
        $db = Database::tenant();
        if ($ids === 'all') {
            $ids = $db->query("SELECT id FROM users WHERE role = 'member'")->fetchAll(\PDO::FETCH_COLUMN);
        }
        $ids = array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
        if (!$ids) {
            Response::error('Choose the members to pay.', 422);
        }
        foreach ($ids as $id) {
            if (Auth::isMe($id)) {
                Response::error('You can\'t pay out interest held for yourself. Ask another super admin.', 403);
            }
        }
        try {
            $paid = Database::transaction(function (\PDO $db) use ($ids, $method, $date, $reference, $moneyAccount) {
                $insert = $db->prepare('INSERT INTO savings_interest_payouts (user_id, amount, method, paid_on, transaction_id, money_account_id, reference, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $paid = [];
                foreach ($ids as $id) {
                    $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$id]);
                    $held = SavingsInterest::held($db, $id);
                    if ($held <= 0) {
                        continue;
                    }
                    $tx = null;
                    if ($method === 'savings') {
                        $tx = Ledger::post($db, $id, 'savings', 'interest', $held, [
                            'value_date' => $date, 'reference' => $reference, 'narration' => 'Interest on savings, paid into savings',
                        ]);
                    }
                    $insert->execute([$id, Money::toDecimal($held), $method, $date, $tx['id'] ?? null, $moneyAccount, $reference, Auth::id()]);
                    $paid[$id] = $held;
                }
                return $paid;
            });
        } catch (LedgerException $e) {
            Response::error($e->getMessage(), 422);
        }
        if (!$paid) {
            Response::error('Nothing is held for the members chosen.', 422);
        }
        Audit::log('savings_interest.paid', null, null, ['method' => $method, 'members' => count($paid), 'total' => Money::toDecimal(array_sum($paid))]);
        foreach ($paid as $id => $cents) {
            Notify::member($id, 'savings_interest', $method === 'savings' ? 'Your savings interest was added to your savings' : 'Your savings interest was paid to you',
                $method === 'savings'
                    ? 'The ' . Notify::cents($cents) . ' interest your savings earned has been added to your savings. It now counts like the rest of your savings.'
                    : 'The ' . Notify::cents($cents) . ' interest your savings earned has been paid to you in cash' . ($reference ? " ($reference)" : '') . '.',
                '/');
        }
        Response::ok(['members' => count($paid), 'total' => Money::toDecimal(array_sum($paid))]);
    }
}
