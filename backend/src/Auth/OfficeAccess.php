<?php

declare(strict_types=1);

namespace Sacco\Auth;

use PDO;
use Sacco\Support\Audit;

/**
 * Office access for a member, kept simple for the super admin: give a member the Admin (or Super
 * admin) role and they keep their member account and their own login; an office account linked
 * to them is made quietly (office_via_member), reached with the round switch. Office accounts that
 * already have a login of their own are linked to their owner's member account automatically when
 * the same person is clear.
 */
final class OfficeAccess
{
    /** The office account linked to a member (any status), or null */
    public static function officeOf(PDO $db, int $memberId): ?array
    {
        // The main super admin is nobody's office access, even if it was once linked to a member
        $stmt = $db->prepare("SELECT * FROM users WHERE linked_member_id = ? AND role <> 'member' AND NOT (role = 'super_admin' AND office_via_member = 0)");
        $stmt->execute([$memberId]);
        return $stmt->fetch() ?: null;
    }

    /** The office role a member holds through their linked office account, or null */
    public static function roleOf(PDO $db, int $memberId): ?string
    {
        $o = self::officeOf($db, $memberId);
        return $o && $o['status'] === 'active' ? $o['role'] : null;
    }

    /** Gives a member office access with this role: their linked office account gets it, made if there is none */
    public static function grant(PDO $db, array $member, string $role, ?int $by): array
    {
        $office = self::officeOf($db, (int) $member['id']);
        if ($office) {
            $db->prepare("UPDATE users SET role = ?, status = 'active', full_name = ? WHERE id = ?")->execute([$role, $member['full_name'], $office['id']]);
        } else {
            // No login of its own: the person logs in as themselves and switches
            $db->prepare(
                "INSERT INTO users (full_name, role, status, source, password_hash, must_change_password, linked_member_id, office_via_member, created_by)
                 VALUES (?, ?, 'active', 'manual', ?, 0, ?, 1, ?)"
            )->execute([$member['full_name'], $role, Passwords::hash(bin2hex(random_bytes(24))), $member['id'], $by]);
            $id = (int) $db->lastInsertId();
            $db->prepare('UPDATE users SET account_no = ? WHERE id = ?')->execute(['M' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
        }
        return self::officeOf($db, (int) $member['id']);
    }

    /** Takes a member's office access away (their office account is suspended, kept for the records) */
    public static function revoke(PDO $db, array $member): ?array
    {
        $office = self::officeOf($db, (int) $member['id']);
        if ($office && $office['status'] === 'active') {
            $db->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$office['id']]);
        }
        return $office;
    }

    /**
     * Links an office account with a login of its own to its owner's member account when the same
     * person is clear: the same national ID, or the same phone, or exactly one member with the same
     * name (in any order). Returns the member's id, or null when no one is clearly them.
     */
    public static function autoLink(PDO $db, array $office): ?int
    {
        if ($office['role'] === Roles::MEMBER || !empty($office['linked_member_id'])) {
            return null;
        }
        $members = $db->query(
            "SELECT u.id, u.full_name, u.phone, u.national_id FROM users u
             WHERE u.role = 'member' AND u.status = 'active'
               AND NOT EXISTS (SELECT 1 FROM users o WHERE o.linked_member_id = u.id)"
        )->fetchAll();
        $name = self::nameKey((string) $office['full_name']);
        $phone = self::phoneKey($office['phone'] ?? null);
        $nid = strtoupper(trim((string) ($office['national_id'] ?? '')));
        $match = null;
        foreach ([
            'national ID' => fn ($m) => $nid !== '' && strtoupper(trim((string) $m['national_id'])) === $nid,
            'phone' => fn ($m) => $phone !== null && self::phoneKey($m['phone']) === $phone,
            'name' => fn ($m) => $name !== '' && self::nameKey((string) $m['full_name']) === $name,
        ] as $by => $same) {
            $found = array_values(array_filter($members, $same));
            if (count($found) === 1) {
                $match = [$found[0], $by];
                break;
            }
        }
        if (!$match) {
            return null;
        }
        [$member, $by] = $match;
        $db->prepare('UPDATE users SET linked_member_id = ? WHERE id = ? AND linked_member_id IS NULL')->execute([$member['id'], $office['id']]);
        Audit::log('users.linked', 'user', (int) $office['id'], ['member' => (int) $member['id'], 'automatically' => true, 'by' => $by]);
        return (int) $member['id'];
    }

    /** "Bright  ARINAITWE" and "Arinaitwe Bright" are the same name */
    private static function nameKey(string $name): string
    {
        $words = preg_split('/[^a-z]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
        sort($words);
        return count($words) >= 2 ? implode(' ', $words) : '';
    }

    /** The last 9 digits: 0772 123456 and +256 772 123456 are the same phone */
    private static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        return strlen($digits) >= 9 ? substr($digits, -9) : null;
    }
}
