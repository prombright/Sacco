<?php

declare(strict_types=1);

namespace Sacco\Support;

use Sacco\Auth\Auth;
use Sacco\Auth\Roles;
use Sacco\Database;

/**
 * Messages in the app (the bell).
 *
 * - members hear about their own money: deposits, withdrawals, repayments, dividends, changes
 *   the office makes to their account
 * - office accounts hear about every change a super admin (or the system provider) makes, so
 *   nobody changes the rules or the books without the rest of the office knowing
 */
final class Notify
{
    /** True while the system itself sends messages (the daily run), so none is signed by a person */
    public static bool $system = false;

    /** True while catching up old records (a quiet upload): nobody is messaged about them */
    public static bool $muted = false;

    public static function member(int $userId, string $kind, string $title, string $body, ?string $link = null): void
    {
        if (self::$muted) {
            return;
        }
        // A copy goes to their email when they have one and haven't turned the copies off
        $email = self::emailOn($userId) && Mailer::enabled();
        Database::tenant()->prepare(
            'INSERT INTO notifications (user_id, title, body, link, kind, created_by, email_status) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$userId, mb_substr($title, 0, 160), $body, $link, $kind, self::$system ? null : Auth::id(), $email ? 'queued' : 'none']);
        if ($email) {
            Outbox::afterReply();
        }
    }

    /** @var array<int, bool> */
    private static array $emailOn = [];

    private static function emailOn(int $userId): bool
    {
        if (!isset(self::$emailOn[$userId])) {
            $stmt = Database::tenant()->prepare("SELECT email, email_alerts, status FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $u = $stmt->fetch();
            self::$emailOn[$userId] = $u && $u['status'] === 'active' && $u['email_alerts'] && filter_var($u['email'], FILTER_VALIDATE_EMAIL);
        }
        return self::$emailOn[$userId];
    }

    /** Every active super admin: things they act on, such as slow payers and repayments falling due */
    public static function superAdmins(string $kind, string $title, string $body, ?string $link = null): void
    {
        $ids = Database::tenant()->prepare("SELECT id FROM users WHERE status = 'active' AND role = ?");
        $ids->execute([Roles::SUPER_ADMIN]);
        foreach ($ids->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            self::member((int) $id, $kind, $title, $body, $link);
        }
    }

    /** Every active admin and super admin, except whoever made the change */
    public static function office(string $kind, string $title, string $body, ?string $link = null, ?int $except = null): void
    {
        $db = Database::tenant();
        $ids = $db->prepare("SELECT id FROM users WHERE status = 'active' AND role IN (?, ?) AND id <> ?");
        $ids->execute([Roles::ADMIN, Roles::SUPER_ADMIN, $except ?? 0]);
        foreach ($ids->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            self::member((int) $id, $kind, $title, $body, $link);
        }
    }

    /** "UGX 1,000,000" in the SACCO's currency, for message text */
    public static function money(string $decimal): string
    {
        $sacco = \Sacco\Tenant::current();
        return ($sacco['currency'] ?? 'UGX') . ' ' . number_format((float) $decimal);
    }

    /** Same, from cents */
    public static function cents(int $cents): string
    {
        return self::money(Money::toDecimal($cents));
    }
}
