<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Audit;
use Sacco\Support\Ledger;
use Sacco\Support\LedgerException;
use Sacco\Support\Money;

/**
 * A member leaving the SACCO (left the school, retired, resigned). They can only leave once they
 * owe nothing and guarantee nobody; then their savings and shares are paid out by cheque, both
 * accounts go to zero, and their account is marked "left".
 */
final class MemberExitController
{
    /** GET /api/members/{id}/exit - what leaving would mean now, and what stops it */
    public function quote(Request $request): never
    {
        $user = $this->member($request);
        Response::ok($this->position((int) $user['id']));
    }

    /** POST /api/members/{id}/exit { reason, cheque_no, exit_date } */
    public function close(Request $request): never
    {
        $user = $this->member($request);
        if ((int) $user['id'] === Auth::id()) {
            Response::error('You can\'t close your own membership. Ask another admin.', 403);
        }
        $reason = mb_substr($request->string('reason'), 0, 255);
        if ($reason === '') {
            Response::error('Give the reason they are leaving.', 422, ['reason' => 'Required']);
        }
        $cheque = mb_substr($request->string('cheque_no'), 0, 80);
        $date = $request->string('exit_date') ?: date('Y-m-d');
        $d = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            Response::error('Enter a valid date, not in the future.', 422);
        }

        $paid = Database::transaction(function (\PDO $db) use ($user, $reason, $cheque, $date) {
            $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$user['id']]);
            $p = $this->position((int) $user['id']);
            if ($p['blockers']) {
                throw new LedgerException($p['blockers'][0]);
            }
            $savings = Money::fromDecimal($p['savings']);
            $shares = Money::fromDecimal($p['shares']);
            if (($savings + $shares) > 0 && $cheque === '') {
                throw new LedgerException('Enter the cheque number for the payout.');
            }
            $meta = ['value_date' => $date, 'reference' => $cheque ? "Cheque $cheque" : null, 'narration' => "Membership closed: $reason"];
            if ($savings > 0) {
                Ledger::post($db, (int) $user['id'], 'savings', 'withdrawal', -$savings, $meta);
            }
            if ($shares > 0) {
                Ledger::post($db, (int) $user['id'], 'shares', 'withdrawal', -$shares, $meta);
            }
            $db->prepare('INSERT INTO member_exits (user_id, reason, savings_paid, shares_paid, cheque_no, exit_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$user['id'], $reason, Money::toDecimal($savings), Money::toDecimal($shares), $cheque ?: null, $date, Auth::id()]);
            $db->prepare("UPDATE users SET status = 'left' WHERE id = ?")->execute([$user['id']]);
            return $savings + $shares;
        });
        Audit::log('member.exited', 'user', (int) $user['id'], ['paid' => Money::toDecimal($paid), 'reason' => $reason]);
        Response::ok(['paid' => Money::toDecimal($paid)]);
    }

    private function member(Request $request): array
    {
        $stmt = Database::tenant()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([(int) $request->param('id')]);
        $user = $stmt->fetch() ?: Response::error('Member not found.', 404);
        if ($user['status'] === 'left') {
            Response::error('This member has already left.', 422);
        }
        return $user;
    }

    /** Balances to pay out, and anything that has to be cleared first */
    private function position(int $userId): array
    {
        $db = Database::tenant();
        $blockers = [];

        $loans = $db->prepare("SELECT id, loan_no, status FROM loans WHERE user_id = ? AND status IN ('pending_guarantors', 'pending_approval', 'approved', 'active')");
        $loans->execute([$userId]);
        $owed = 0;
        foreach ($loans->fetchAll() as $l) {
            if ($l['status'] === 'active') {
                $left = Loans::position($db, (int) $l['id'])['total_left'];
                $owed += $left;
                $blockers[] = "Loan {$l['loan_no']} still has " . number_format($left / 100) . ' to pay. Record its repayment (from their savings if agreed) or settle it first.';
            } else {
                $blockers[] = "Loan application {$l['loan_no']} is still open. Cancel or reject it first.";
            }
        }
        if (($pledged = Loans::pledged($db, $userId)) > 0) {
            $blockers[] = 'They guarantee other members\' loans (' . number_format($pledged / 100) . ' pledged). Those loans need other guarantors or repaying first.';
        }
        $w = $db->prepare("SELECT request_no FROM withdrawal_requests WHERE user_id = ? AND status IN ('pending', 'approved')");
        $w->execute([$userId]);
        if ($no = $w->fetchColumn()) {
            $blockers[] = "Withdrawal request $no is still open. Finish or reject it first.";
        }

        return [
            'savings' => Money::toDecimal(Ledger::balance($db, $userId, 'savings')),
            'shares' => Money::toDecimal(Ledger::balance($db, $userId, 'shares')),
            'loans_owed' => Money::toDecimal($owed),
            'blockers' => $blockers,
        ];
    }
}
