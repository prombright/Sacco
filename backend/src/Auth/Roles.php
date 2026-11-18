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
    /** Sees everything in the office; approves and changes nothing */
    public const STAFF = 'staff';
    public const MEMBER = 'member';

    public const ALL = [self::SUPER_ADMIN, self::ADMIN, self::STAFF, self::MEMBER];

    /** Office roles, from the top */
    public const OFFICE = [self::SUPER_ADMIN, self::ADMIN, self::STAFF];

    /**
     * Whether a role may open something meant for these roles. Staff may look wherever admins may
     * (doing anything goes through can(), which staff never pass).
     */
    public static function allows(string $role, array $allowed): bool
    {
        return $role === self::SUPER_ADMIN || in_array($role, $allowed, true)
            || ($role === self::STAFF && in_array(self::ADMIN, $allowed, true));
    }

    /** What the main super admin is called to everyone else: they don't see who it is */
    public const MAIN_NAME = 'System administrator';

    /**
     * The main super admin: a super admin login of its own, made in the developer portal and not
     * anyone's member account. It makes members super admins (and takes that away); it never
     * switches into a member account, and the rest of the office doesn't see it.
     */
    public static function isMain(?array $user): bool
    {
        return $user !== null && ($user['role'] ?? null) === self::SUPER_ADMIN && empty($user['office_via_member']);
    }

    /** SQL for the name an office person sees for whoever is in `$alias` */
    public static function shownNameSql(string $alias): string
    {
        return "CASE WHEN $alias.role = 'super_admin' AND $alias.office_via_member = 0 THEN '" . self::MAIN_NAME . "' ELSE $alias.full_name END";
    }

    /** Only a super admin may create, change or reset admins and super admins */
    public static function canManage(string $actorRole, string $targetRole): bool
    {
        return $actorRole === self::SUPER_ADMIN
            || ($actorRole === self::ADMIN && $targetRole === self::MEMBER);
    }

    /** Areas the super admin can open to admins (Setup page); admins can always look and download */
    public const PERMISSIONS = [
        'members' => 'Add and change members, import them, and close accounts',
        'money' => 'Record and reverse money: deposits, payroll, transfers, withdrawals, fees, income, expenses, journals',
        'loans' => 'Pay out and manage loans (admins approve loans by their role)',
        'notices' => 'Post and take down notices',
    ];

    /** Whether this user may do things in an area; super admins always may */
    public static function can(array $user, string $permission): bool
    {
        if ($user['role'] === self::SUPER_ADMIN) {
            return true;
        }
        return $user['role'] === self::ADMIN && \Sacco\Support\Settings::get("admins_can_$permission", 'no') === 'yes';
    }

    /** @return string[] the areas this user may change */
    public static function permissions(array $user): array
    {
        return array_values(array_filter(array_keys(self::PERMISSIONS), fn ($p) => self::can($user, $p)));
    }

    public static function label(string $role): string
    {
        return match ($role) {
            self::SUPER_ADMIN => 'Super Admin',
            self::ADMIN => 'Admin',
            self::STAFF => 'Staff',
            default => 'Member',
        };
    }
}
