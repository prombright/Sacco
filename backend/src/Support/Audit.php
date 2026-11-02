<?php

declare(strict_types=1);

namespace Sacco\Support;

use Sacco\Auth\Auth;
use Sacco\Database;

/**
 * The audit log: who did what, to what, from where. Rows are only ever added.
 */
final class Audit
{
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
    }
}
