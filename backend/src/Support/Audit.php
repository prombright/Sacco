<?php

declare(strict_types=1);

namespace Sacco\Support;

use Sacco\Auth\Auth;
use Sacco\Auth\Roles;
use Sacco\Database;

/**
 * The audit log: who did what, to what, from where. Rows are only ever added.
 *
 * Whatever a super admin does is also sent to every other office account, so changes to the
 * rules and the books are always seen by more than one person.
 */
final class Audit
{
    /** Routine work the office doesn't need a message for (it's all in the activity log) */
    private const QUIET = ['password.changed', 'transaction.recorded', 'loan.payment_recorded', 'membership_fee.paid', 'next_of_kin.saved', 'withdrawal.requested', 'loan.applied', 'two_step.enabled', 'two_step.disabled', 'user.unlocked'];

    public static function log(string $action, ?string $subjectType = null, ?int $subjectId = null, array $details = [], ?int $actorId = null): void
    {
        Database::tenant()->prepare(
            'INSERT INTO audit_log (actor_id, action, subject_type, subject_id, details, ip) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $actorId ?? Auth::id(),
            $action,
            $subjectType,
            $subjectId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $actor = Auth::user();
        if ($actor && $actor['role'] === Roles::SUPER_ADMIN && !in_array($action, self::QUIET, true)) {
            $row = ['action' => $action, 'details' => $details, 'subject_type' => $subjectType, 'subject_id' => $subjectId];
            self::tellOffice($actor['full_name'] . ' (super admin) ' . Activity::describe($row), $row, (int) $actor['id']);
        }
    }

    /** Sends a change to every office account; a failure here never undoes the change itself */
    public static function tellOffice(string $title, array $row, ?int $except = null): void
    {
        try {
            $body = trim(Activity::explain($row) . "\nOn " . date('j M Y \a\t H:i') . '.');
            Notify::office('accountability', $title, $body, Activity::link($row), $except);
        } catch (\Throwable $e) {
            error_log('[sacco] could not notify the office: ' . $e->getMessage());
        }
    }
}
