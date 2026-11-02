<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Auth\Roles;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Audit;
use Sacco\Support\Ledger;
use Sacco\Support\LedgerException;
use Sacco\Support\Money;
use Sacco\Support\Savings;

/**
 * Savings withdrawals: a member asks online, an admin approves, then records the cheque - only
 * then does the money leave the savings ledger. Admins never handle their own request.
 */
final class WithdrawalController
{
    private const OPEN = ['pending', 'approved'];

    /** GET /api/me/withdrawals - their requests and what they can withdraw now */
    public function mine(Request $request): never
    {
        $db = Database::tenant();
        $me = (int) Auth::id();
        $stmt = $db->prepare(self::SELECT . ' WHERE w.user_id = ? ORDER BY w.id DESC');
        $stmt->execute([$me]);
        Response::ok([
            'requests' => array_map([self::class, 'present'], $stmt->fetchAll()),
            'savings' => Money::toDecimal(Ledger::balance($db, $me, 'savings')),
            'pledged' => Money::toDecimal(Loans::pledged($db, $me)),
            'held' => Money::toDecimal(Savings::heldForWithdrawals($db, $me)),
            'available' => Money::toDecimal(Savings::free($db, $me)),
        ]);
    }

    /** POST /api/withdrawals { amount, reason? } */
    public function store(Request $request): never
    {
        $me = (int) Auth::id();
        $cents = Money::parse($request->input('amount'));
        if ($cents === null || $cents <= 0) {
            Response::error('Enter the amount you want to withdraw.', 422, ['amount' => 'Required']);
        }
        $reason = mb_substr($request->string('reason'), 0, 255) ?: null;

        $id = Database::transaction(function (\PDO $db) use ($me, $cents, $reason) {
            $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$me]);
            $open = $db->prepare("SELECT request_no FROM withdrawal_requests WHERE user_id = ? AND status IN ('pending', 'approved') LIMIT 1");
            $open->execute([$me]);
            if ($no = $open->fetchColumn()) {
                throw new LedgerException("You already have a withdrawal request in progress ($no).");
            }
            $free = Savings::free($db, $me);
            if ($cents > $free) {
                throw new LedgerException('You can withdraw up to ' . number_format($free / 100) . ' right now.');
            }
            $db->prepare('INSERT INTO withdrawal_requests (user_id, amount, reason) VALUES (?, ?, ?)')->execute([$me, Money::toDecimal($cents), $reason]);
            $id = (int) $db->lastInsertId();
            $db->prepare('UPDATE withdrawal_requests SET request_no = ? WHERE id = ?')->execute(['W' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
            return $id;
        });
        Audit::log('withdrawal.requested', 'withdrawal', $id, ['amount' => Money::toDecimal($cents)]);

        Response::ok(['request' => $this->find($id)], 201);
    }

    /** GET /api/withdrawals/{id} - the member's own, or any for an admin (the slip is built from this) */
    public function show(Request $request): never
    {
        $w = $this->find((int) $request->param('id'));
        $user = Auth::user();
        if ($w['user_id'] !== (int) $user['id'] && !Roles::allows($user['role'], [Roles::ADMIN])) {
            Response::error('Request not found.', 404);
        }
        Response::ok(['request' => $w]);
    }

    /** POST /api/withdrawals/{id}/cancel - the member, before the cheque */
    public function cancel(Request $request): never
    {
        $w = $this->find((int) $request->param('id'));
        if ($w['user_id'] !== Auth::id()) {
            Response::error('Request not found.', 404);
        }
        if (!in_array($w['status'], self::OPEN, true)) {
            Response::error('This request can no longer be cancelled.', 422);
        }
        $this->setStatus($w['id'], 'cancelled', null);
        Audit::log('withdrawal.cancelled', 'withdrawal', $w['id']);
        Response::ok(['request' => $this->find($w['id'])]);
    }

    /** GET /api/withdrawals?status= (admin) */
    public function index(Request $request): never
    {
        $db = Database::tenant();
        $status = $request->string('status') ?: 'pending';
        $stmt = $db->prepare(self::SELECT . ' WHERE w.status = ? ORDER BY w.id ' . (in_array($status, self::OPEN, true) ? 'ASC' : 'DESC') . ' LIMIT 200');
        $stmt->execute([$status]);
        $rows = array_map([self::class, 'present'], $stmt->fetchAll());
        // So the admin can see the money is still there before approving
        foreach ($rows as &$row) {
            if (in_array($row['status'], self::OPEN, true)) {
                $row['available_now'] = Money::toDecimal(Savings::free($db, $row['user_id'], $row['id']));
            }
        }
        unset($row);
        Response::ok([
            'requests' => $rows,
            'counts' => array_map('intval', $db->query('SELECT status, COUNT(*) FROM withdrawal_requests GROUP BY status')->fetchAll(\PDO::FETCH_KEY_PAIR)),
        ]);
    }

    /** POST /api/withdrawals/{id}/approve (admin) */
    public function approve(Request $request): never
    {
        $w = $this->findForAdmin((int) $request->param('id'));
        if ($w['status'] !== 'pending') {
            Response::error('Only a pending request can be approved.', 422);
        }
        $this->setStatus($w['id'], 'approved', mb_substr($request->string('note'), 0, 255) ?: null);
        Audit::log('withdrawal.approved', 'withdrawal', $w['id']);
        Response::ok(['request' => $this->find($w['id'])]);
    }

    /** POST /api/withdrawals/{id}/reject { note } (admin) */
    public function reject(Request $request): never
    {
        $w = $this->findForAdmin((int) $request->param('id'));
        if (!in_array($w['status'], self::OPEN, true)) {
            Response::error('This request can no longer be rejected.', 422);
        }
        $note = mb_substr($request->string('note'), 0, 255);
        if ($note === '') {
            Response::error('Give the reason - the member will see it.', 422, ['note' => 'Required']);
        }
        $this->setStatus($w['id'], 'rejected', $note);
        Audit::log('withdrawal.rejected', 'withdrawal', $w['id'], ['note' => $note]);
        Response::ok(['request' => $this->find($w['id'])]);
    }

    /** POST /api/withdrawals/{id}/pay { cheque_no, paid_on } (admin) - the cheque was issued; savings go down */
    public function pay(Request $request): never
    {
        $w = $this->findForAdmin((int) $request->param('id'));
        if ($w['status'] !== 'approved') {
            Response::error('Approve the request before recording the cheque.', 422);
        }
        $cheque = mb_substr($request->string('cheque_no'), 0, 80);
        if ($cheque === '') {
            Response::error('Enter the cheque number.', 422, ['cheque_no' => 'Required']);
        }
        $paidOn = $request->string('paid_on') ?: date('Y-m-d');
        $d = \DateTime::createFromFormat('!Y-m-d', $paidOn);
        if (!$d || $d->format('Y-m-d') !== $paidOn || $paidOn > date('Y-m-d')) {
            Response::error('Enter a valid date, not in the future.', 422, ['paid_on' => 'Invalid']);
        }

        Database::transaction(function (\PDO $db) use ($w, $cheque, $paidOn) {
            $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$w['user_id']]);
            $cents = Money::fromDecimal($w['amount']);
            $free = Savings::free($db, $w['user_id'], $w['id']);
            if ($cents > $free) {
                throw new LedgerException('The member now has only ' . number_format($free / 100) . ' free in savings. Reject this request and ask them to make a new one.');
            }
            $row = Ledger::post($db, $w['user_id'], 'savings', 'withdrawal', -$cents, [
                'value_date' => $paidOn,
                'reference' => 'Cheque ' . $cheque,
                'narration' => 'Withdrawal ' . $w['request_no'],
            ]);
            $db->prepare("UPDATE withdrawal_requests SET status = 'paid', cheque_no = ?, paid_on = ?, paid_by = ?, transaction_id = ? WHERE id = ? AND status = 'approved'")
                ->execute([$cheque, $paidOn, Auth::id(), $row['id'], $w['id']]);
        });
        Audit::log('withdrawal.paid', 'withdrawal', $w['id'], ['cheque' => $cheque]);
        Response::ok(['request' => $this->find($w['id'])]);
    }

    // ----------------------------------------------------------------------

    private const SELECT = 'SELECT w.*, u.full_name, u.account_no, u.employee_no,
            d.full_name AS decided_by_name, p.full_name AS paid_by_name
        FROM withdrawal_requests w
        JOIN users u ON u.id = w.user_id
        LEFT JOIN users d ON d.id = w.decided_by
        LEFT JOIN users p ON p.id = w.paid_by';

    private static function present(array $w): array
    {
        return [
            'id' => (int) $w['id'],
            'request_no' => $w['request_no'],
            'user_id' => (int) $w['user_id'],
            'full_name' => $w['full_name'],
            'account_no' => $w['account_no'],
            'employee_no' => $w['employee_no'],
            'amount' => $w['amount'],
            'reason' => $w['reason'],
            'status' => $w['status'],
            'decided_by_name' => $w['decided_by_name'],
            'decided_at' => $w['decided_at'],
            'decision_note' => $w['decision_note'],
            'cheque_no' => $w['cheque_no'],
            'paid_on' => $w['paid_on'],
            'paid_by_name' => $w['paid_by_name'],
            'created_at' => $w['created_at'],
        ];
    }

    private function find(int $id): array
    {
        $stmt = Database::tenant()->prepare(self::SELECT . ' WHERE w.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: Response::error('Request not found.', 404);
        return self::present($row);
    }

    private function findForAdmin(int $id): array
    {
        $w = $this->find($id);
        if ($w['user_id'] === Auth::id()) {
            Response::error('This is your own request. Another admin has to handle it.', 403);
        }
        return $w;
    }

    private function setStatus(int $id, string $status, ?string $note): void
    {
        Database::tenant()->prepare('UPDATE withdrawal_requests SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = COALESCE(?, decision_note) WHERE id = ?')
            ->execute([$status, Auth::id(), $note, $id]);
    }
}
