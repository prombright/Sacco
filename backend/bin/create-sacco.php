<?php

declare(strict_types=1);

/**
 * Adds a SACCO: its row in the platform database, its own database with all tables, and its
 * first super admin (who gets a temporary password, printed once, to change at first login).
 *
 *   php backend/bin/create-sacco.php --code=smacon --name="SMACON SACCO" --short=SMACON \
 *       --admin-name="Jane Doe" --admin-email=jane@example.com [--db=sacco_smacon] [--domain=]
 *
 * On cPanel, create the database (and give the MySQL user access to it) in cPanel first and pass
 * its full name with --db.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use Sacco\Auth\Passwords;
use Sacco\Config;
use Sacco\Database;
use Sacco\Support\Migrator;

$opt = getopt('', ['code:', 'name:', 'short:', 'admin-name:', 'admin-email:', 'db:', 'domain:', 'currency:']);
$code = strtolower(trim($opt['code'] ?? ''));
$name = trim($opt['name'] ?? '');
$adminName = trim($opt['admin-name'] ?? '');
$adminEmail = strtolower(trim($opt['admin-email'] ?? ''));

if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}$/', $code) || $name === '' || $adminName === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    exit("Usage: php backend/bin/create-sacco.php --code=smacon --name=\"SMACON SACCO\" --admin-name=\"Jane Doe\" --admin-email=jane@example.com [--short=SMACON] [--db=sacco_smacon] [--domain=host] [--currency=UGX]\n");
}
$dbName = $opt['db'] ?? 'sacco_' . str_replace('-', '_', $code);

// The platform database first, so the saccos table exists
$platform = (string) Config::get('PLATFORM_DB_NAME', 'sacco_platform');
Migrator::ensureDatabase($platform);
Migrator::run($platform, 'platform');

$exists = Database::platform()->prepare('SELECT 1 FROM saccos WHERE code = ?');
$exists->execute([$code]);
if ($exists->fetchColumn()) {
    exit("A SACCO with code '$code' already exists.\n");
}

Migrator::ensureDatabase($dbName);
echo "Setting up $dbName\n";
Migrator::run($dbName, 'tenant', fn ($l) => print($l . PHP_EOL));

Database::platform()->prepare(
    'INSERT INTO saccos (code, name, short_name, domain, db_name, currency) VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$code, $name, $opt['short'] ?? null, ($opt['domain'] ?? '') ?: null, $dbName, strtoupper($opt['currency'] ?? 'UGX')]);

$temporary = Passwords::temporary();
$db = Database::connect($dbName);
$db->prepare(
    "INSERT INTO users (full_name, email, role, password_hash, must_change_password) VALUES (?, ?, 'super_admin', ?, 1)"
)->execute([$adminName, $adminEmail, Passwords::hash($temporary)]);
$id = (int) $db->lastInsertId();
$db->prepare('UPDATE users SET account_no = ? WHERE id = ?')->execute(['M' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
$db->prepare("INSERT INTO audit_log (actor_id, action, subject_type, subject_id) VALUES (NULL, 'sacco.created', 'user', ?)")->execute([$id]);

echo "\nSACCO '$name' is ready (code: $code).\n";
echo "Super admin: $adminEmail\n";
echo "Temporary password: $temporary   (shown once - they must change it at first login)\n";
