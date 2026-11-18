<?php

declare(strict_types=1);

namespace Sacco\Support;

use Sacco\Auth\Auth;
use Sacco\Auth\Roles;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;

/**
 * Two people for big money (maker-checker): an entry, reversal, transfer, cash book entry or journal
 * at or above the SACCO's limit (Setup: approval_limit, 0 = off) is kept, not done, until a second
 * office person with that right approves it; the request is then carried out exactly as it was
 * made, in the approver's name. Nobody approves their own request or one on their own money.
 *
 * A withdrawal cheque or a loan payout above the limit already has two steps (approve, then pay):
 * there the two steps must be two different people (sameApprover()).
 */
final class Approvals
{
    /** The approval being carried out now, so its replayed request isn't held again */
    public static ?int $carrying = null;

    /** In cents; 0 = no second approval needed */
    public static function limit(): int
    {
        return (int) (Money::parse(Settings::get('approval_limit', '0')) ?? 0);
    }

    public static function needed(int $cents): bool
    {
        $limit = self::limit();
        return self::$carrying === null && $limit > 0 && $cents >= $limit;
    }

    /**
     * Called by an action once its input is checked and before it changes anything: at or above the
     * limit, the request is kept for a second person and this request ends (202).
     *
     * @param callable-string|array{0: class-string, 1: string} $handler the action, to carry out on approval
     */
    public static function gate(Request $request, array $handler, string $kind, string $area, int $cents, string $summary, ?int $memberId = null): void
    {
        if (!self::needed($cents)) {
            return;
        }
        $db = Database::tenant();
        $db->prepare(
            'INSERT INTO approvals (kind, area, handler, method, path, params, body, amount, summary, member_id, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $kind, $area, $handler[0] . '::' . $handler[1], $request->method, $request->path,
            json_encode($request->params), json_encode($request->json(), JSON_UNESCAPED_UNICODE),
            Money::toDecimal($cents), mb_substr($summary, 0, 255), $memberId, Auth::id(),
        ]);
        $id = (int) $db->lastInsertId();
        Audit::log('approval.requested', 'approval', $id, ['summary' => $summary, 'amount' => Money::toDecimal($cents)]);
        $who = Auth::user()['full_name'];
        Notify::office('accountability', "$who asks for a second approval", "$summary. Open Waiting for approval to approve or reject it.", '/admin/approvals', (int) Auth::id());
        Response::ok(['pending_approval' => ['id' => $id, 'summary' => $summary, 'limit' => Money::toDecimal(self::limit())]], 202);
    }

    /**
     * A two-step action (withdrawal cheque, loan payout) at or above the limit: the second step must
     * be someone other than whoever approved the first.
     */
    public static function sameApprover(int $cents, ?int $approvedBy, string $what): void
    {
        if (self::needed($cents) && $approvedBy !== null && Auth::isMe($approvedBy)) {
            Response::error("Above " . Notify::cents(self::limit()) . ", the person who $what can't be the one who approved it. Ask another office member.", 403);
        }
    }

    /** Whether this user may approve requests in this area */
    public static function mayApprove(array $user, string $area): bool
    {
        return Roles::can($user, $area);
    }
}
