<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Approvals;
use Sacco\Support\Audit;
use Sacco\Support\Notify;

/** Waiting for approval: big money actions a second office person approves or rejects */
final class ApprovalController
{
    /** GET /api/approvals?status=pending|done (office) */
    public function index(Request $request): never
    {
        $done = $request->string('status') === 'done';
        $stmt = Database::tenant()->query(
            "SELECT a.id, a.kind, a.area, a.amount, a.summary, a.member_id, a.status, a.requested_at, a.decided_at, a.note,
                    a.requested_by, CASE WHEN r.role = 'super_admin' AND r.office_via_member = 0 THEN 'System administrator' ELSE r.full_name END AS requested_by_name, CASE WHEN d.role = 'super_admin' AND d.office_via_member = 0 THEN 'System administrator' ELSE d.full_name END AS decided_by_name
             FROM approvals a JOIN users r ON r.id = a.requested_by LEFT JOIN users d ON d.id = a.decided_by
             WHERE a.status " . ($done ? "<> 'pending'" : "= 'pending'") . ' ORDER BY a.id DESC LIMIT 200'
        );
        $user = Auth::user();
        $rows = array_map(function ($a) use ($user) {
            $mine = Auth::isMe((int) $a['requested_by']);
            $onMe = $a['member_id'] !== null && Auth::isMe((int) $a['member_id']);
            return $a + [
                'mine' => $mine,
                // Why this person can't approve it, or null when they can
                'cannot' => $mine ? 'You asked for this one: someone else approves it.'
                    : ($onMe ? 'It is on your own money: someone else approves it.'
                    : (!Approvals::mayApprove($user, $a['area']) ? 'Only the super admin, or an admin allowed in this area, can approve it.' : null)),
            ];
        }, $stmt->fetchAll());
        Response::ok(['approvals' => $rows, 'limit' => \Sacco\Support\Money::toDecimal(Approvals::limit())]);
    }

    /** POST /api/approvals/{id}/approve (office) - carries the request out, in the approver's name */
    public function approve(Request $request): never
    {
        $a = $this->find((int) $request->param('id'));
        $this->mayDecide($a);
        $db = Database::tenant();
        // One approver at a time: the row is claimed, and freed again if the action can't be done now
        $claim = $db->prepare("UPDATE approvals SET status = 'approving', decided_by = ? WHERE id = ? AND status = 'pending'");
        $claim->execute([Auth::id(), $a['id']]);
        if ($claim->rowCount() !== 1) {
            Response::error('Someone else has just decided this one.', 409);
        }
        [$class, $method] = explode('::', $a['handler']);
        if (!class_exists($class) || !method_exists($class, $method)) {
            $db->prepare("UPDATE approvals SET status = 'pending', decided_by = NULL WHERE id = ?")->execute([$a['id']]);
            Response::error('This kind of request can no longer be carried out.', 422);
        }
        // The action ends the request itself: whether it worked is settled once it has answered
        register_shutdown_function(function () use ($a) {
            $ok = http_response_code() < 300;
            $db = Database::tenant();
            if ($ok) {
                $db->prepare("UPDATE approvals SET status = 'approved', decided_at = NOW() WHERE id = ?")->execute([$a['id']]);
                Audit::log('approval.approved', 'approval', (int) $a['id'], ['summary' => $a['summary'], 'amount' => $a['amount'], 'requested_by' => (int) $a['requested_by']]);
                Notify::member((int) $a['requested_by'], 'accountability', 'Your request was approved', $a['summary'] . ' was approved by ' . Auth::user()['full_name'] . ' and is done.', '/admin/approvals');
            } else {
                $db->prepare("UPDATE approvals SET status = 'pending', decided_by = NULL WHERE id = ?")->execute([$a['id']]);
            }
        });
        Approvals::$carrying = (int) $a['id'];
        $replay = Request::replay($a['method'], $a['path'], json_decode($a['params'], true) ?: [], json_decode($a['body'], true) ?: []);
        (new $class())->$method($replay);
    }

    /** POST /api/approvals/{id}/reject { note } (office) */
    public function reject(Request $request): never
    {
        $a = $this->find((int) $request->param('id'));
        $this->mayDecide($a);
        $note = mb_substr($request->string('note'), 0, 255);
        if ($note === '') {
            Response::error('Say why it is rejected (the person who asked is told).', 422, ['note' => 'Required']);
        }
        $this->close($a, 'rejected', $note);
        Notify::member((int) $a['requested_by'], 'accountability', 'Your request was not approved', $a['summary'] . ' was rejected by ' . Auth::user()['full_name'] . ": $note", '/admin/approvals');
        Response::ok();
    }

    /** POST /api/approvals/{id}/cancel - the person who asked takes it back */
    public function cancel(Request $request): never
    {
        $a = $this->find((int) $request->param('id'));
        if (!Auth::isMe((int) $a['requested_by'])) {
            Response::error('Only the person who asked can take it back.', 403);
        }
        $this->close($a, 'cancelled', null);
        Response::ok();
    }

    private function close(array $a, string $status, ?string $note): void
    {
        $stmt = Database::tenant()->prepare("UPDATE approvals SET status = ?, decided_by = ?, decided_at = NOW(), note = ? WHERE id = ? AND status = 'pending'");
        $stmt->execute([$status, Auth::id(), $note, $a['id']]);
        if ($stmt->rowCount() !== 1) {
            Response::error('Someone else has just decided this one.', 409);
        }
        Audit::log("approval.$status", 'approval', (int) $a['id'], ['summary' => $a['summary'], 'amount' => $a['amount'], 'note' => $note]);
    }

    private function mayDecide(array $a): void
    {
        if (Auth::isMe((int) $a['requested_by'])) {
            Response::error('You asked for this one: another office member approves it.', 403);
        }
        if ($a['member_id'] !== null && Auth::isMe((int) $a['member_id'])) {
            Response::error('It is on your own money: another office member approves it.', 403);
        }
        if (!Approvals::mayApprove(Auth::user(), $a['area'])) {
            Response::error('Only the super admin, or an admin allowed in this area, can approve it.', 403);
        }
    }

    private function find(int $id): array
    {
        $stmt = Database::tenant()->prepare('SELECT * FROM approvals WHERE id = ?');
        $stmt->execute([$id]);
        $a = $stmt->fetch() ?: Response::error('Request not found.', 404);
        if ($a['status'] !== 'pending') {
            Response::error('This request has already been ' . $a['status'] . '.', 422);
        }
        return $a;
    }
}
