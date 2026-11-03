<?php

declare(strict_types=1);

namespace Sacco\Support;

use Sacco\Auth\Auth;
use Sacco\Database;

/** Sends a member a message in the app (the bell). */
final class Notify
{
    public static function member(int $userId, string $kind, string $title, string $body, ?string $link = null): void
    {
        Database::tenant()->prepare(
            'INSERT INTO notifications (user_id, title, body, link, kind, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, mb_substr($title, 0, 160), $body, $link, $kind, Auth::id()]);
    }

    /** "UGX 1,000,000" in the SACCO's currency, for message text */
    public static function money(string $decimal): string
    {
        $sacco = \Sacco\Tenant::current();
        return ($sacco['currency'] ?? 'UGX') . ' ' . number_format((float) $decimal);
    }
}
