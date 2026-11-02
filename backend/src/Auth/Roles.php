<?php

declare(strict_types=1);

namespace Sacco\Auth;

/**
 * - super_admin: can do everything, including managing other admins
 * - admin: runs the SACCO day to day (members, balances, approvals)
 * - member: a staff member who saves and borrows
 */
final class Roles
{
    public const SUPER_ADMIN = 'super_admin';
    public const ADMIN = 'admin';
    public const MEMBER = 'member';

    public const ALL = [self::SUPER_ADMIN, self::ADMIN, self::MEMBER];

    public static function allows(string $role, array $allowed): bool
    {
        return $role === self::SUPER_ADMIN || in_array($role, $allowed, true);
    }

    /** Only a super admin may create, change or reset admins and super admins */
    public static function canManage(string $actorRole, string $targetRole): bool
    {
        return $actorRole === self::SUPER_ADMIN
            || ($actorRole === self::ADMIN && $targetRole === self::MEMBER);
    }

    public static function label(string $role): string
    {
        return match ($role) {
            self::SUPER_ADMIN => 'Super Admin',
            self::ADMIN => 'Admin',
            default => 'Member',
        };
    }
}
