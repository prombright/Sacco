<?php

declare(strict_types=1);

namespace Sacco\Support;

use Sacco\Auth\Passwords;
use Sacco\Config;
use Sacco\Database;

/**
 * Adds a SACCO: its row in the platform database, its own database with all tables, and its
 * first super admin with a temporary password. Used by bin/create-sacco.php and the developer
 * portal.
 */
final class SaccoSetup
{
    /**
     * @param array{code: string, name: string, short_name?: ?string, domain?: ?string, db_name?: ?string,
     *              currency?: ?string, admin_name: string, admin_email: string} $o
     * @return array{id: int, code: string, db_name: string, admin_email: string, temporary_password: string}
     * @throws \InvalidArgumentException with a message for the person setting it up
     */
    public static function create(array $o, ?callable $say = null): array
    {
        $code = strtolower(trim($o['code'] ?? ''));
        $name = trim($o['name'] ?? '');
        $adminName = trim($o['admin_name'] ?? '');
        $adminEmail = strtolower(trim($o['admin_email'] ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}$/', $code)) {
            throw new \InvalidArgumentException('The code must be 2-39 lowercase letters, digits or dashes, e.g. "smacon".');
        }
        if ($name === '' || $adminName === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Give the SACCO a name, and its first super admin a name and a valid email.');
        }
        $dbName = trim((string) ($o['db_name'] ?? '')) ?: 'sacco_' . str_replace('-', '_', $code);
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName)) {
            throw new \InvalidArgumentException('The database name may only use letters, digits and underscores.');
        }

        // The platform database first, so the saccos table exists
        $platform = (string) Config::get('PLATFORM_DB_NAME', 'sacco_platform');
        Migrator::ensureDatabase($platform);
        Migrator::run($platform, 'platform');

        $exists = Database::platform()->prepare('SELECT 1 FROM saccos WHERE code = ? OR db_name = ?');
        $exists->execute([$code, $dbName]);
        if ($exists->fetchColumn()) {
            throw new \InvalidArgumentException("A SACCO with code '$code' or database '$dbName' already exists.");
        }

        try {
            Migrator::ensureDatabase($dbName);
        } catch (\PDOException $e) {
            throw new \InvalidArgumentException("Couldn't create the database $dbName. On cPanel, create it (and give the MySQL user access) in cPanel first, then enter its full name here.");
        }
        $say && $say("Setting up $dbName");
        Migrator::run($dbName, 'tenant', $say);

        $domain = trim((string) ($o['domain'] ?? '')) ?: null;
        Database::platform()->prepare(
            'INSERT INTO saccos (code, name, short_name, domain, db_name, currency) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$code, $name, trim((string) ($o['short_name'] ?? '')) ?: null, $domain ? strtolower($domain) : null, $dbName, strtoupper(trim((string) ($o['currency'] ?? '')) ?: 'UGX')]);
        $saccoId = (int) Database::platform()->lastInsertId();

        $temporary = Passwords::temporary();
        $db = Database::connect($dbName);
        $db->prepare(
            "INSERT INTO users (full_name, email, role, password_hash, must_change_password) VALUES (?, ?, 'super_admin', ?, 1)"
        )->execute([$adminName, $adminEmail, Passwords::hash($temporary)]);
        $id = (int) $db->lastInsertId();
        $db->prepare('UPDATE users SET account_no = ? WHERE id = ?')->execute(['M' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
        $db->prepare("INSERT INTO audit_log (actor_id, action, subject_type, subject_id) VALUES (NULL, 'sacco.created', 'user', ?)")->execute([$id]);

        return ['id' => $saccoId, 'code' => $code, 'db_name' => $dbName, 'admin_email' => $adminEmail, 'temporary_password' => $temporary];
    }
}
