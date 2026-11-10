<?php

declare(strict_types=1);

/**
 * Gets someone back in when they can't sign in, from the server. Without --login it lists the
 * SACCO's office accounts (super admins and admins) and whether each is locked, so you can see
 * which email to use; with --login it gives that account a new temporary password, unlocks it and
 * turns off two-step sign-in. They choose their own password when they sign in.
 *
 *   php backend/bin/reset-password.php                       (lists office accounts)
 *   php backend/bin/reset-password.php --login=jane@example.com
 *   php backend/bin/reset-password.php --sacco=smacon --login=4556
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use Sacco\Auth\Passwords;
use Sacco\Config;
use Sacco\Database;

$opt = getopt('', ['sacco:', 'login:']);
$code = trim($opt['sacco'] ?? '') ?: (string) Config::get('DEFAULT_SACCO_CODE', '');
$find = Database::platform()->prepare('SELECT code, name, db_name FROM saccos WHERE code = ?');
$find->execute([$code]);
$sacco = $find->fetch();
if (!$sacco) {
    $codes = Database::platform()->query('SELECT code FROM saccos ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    exit("No SACCO with code '$code'. SACCOs here: " . (implode(', ', $codes) ?: 'none') . "\nUse --sacco=<code>.\n");
}
$db = Database::connect($sacco['db_name']);
$login = trim($opt['login'] ?? '');

if ($login === '') {
    echo "Office accounts of {$sacco['name']} ({$sacco['code']}, database {$sacco['db_name']}):\n\n";
    $rows = $db->query("SELECT full_name, email, employee_no, role, status, locked_until, totp_enabled_at, must_change_password, last_login_at
                        FROM users WHERE role IN ('super_admin', 'admin') ORDER BY role = 'admin', id")->fetchAll();
    foreach ($rows as $u) {
        $notes = [];
        if ($u['status'] !== 'active') $notes[] = $u['status'];
        if ($u['locked_until'] && strtotime($u['locked_until']) > time()) $notes[] = 'LOCKED until ' . $u['locked_until'];
        if ($u['totp_enabled_at']) $notes[] = 'two-step on';
        if ($u['must_change_password']) $notes[] = 'still on a temporary password';
        $notes[] = $u['last_login_at'] ? 'last signed in ' . $u['last_login_at'] : 'never signed in';
        printf("  %-11s %-28s sign in with: %s\n              %s\n", $u['role'] === 'super_admin' ? 'SUPER ADMIN' : 'admin', $u['full_name'], $u['email'] ?: $u['employee_no'], implode(' · ', $notes));
    }
    echo $rows ? "\nTo reset one: php backend/bin/reset-password.php --login=<the sign-in shown above>\n" : "  (none)\n";
    exit;
}

$stmt = $db->prepare('SELECT id, full_name, email, employee_no, status FROM users WHERE email = ? OR employee_no = ? LIMIT 1');
$stmt->execute([$login, $login]);
$user = $stmt->fetch();
if (!$user) {
    exit("No account signs in with '$login' in {$sacco['name']}. Run without --login to list the office accounts.\n");
}
$temporary = Passwords::temporary();
$db->prepare("UPDATE users SET password_hash = ?, must_change_password = 1, password_changed_at = NOW(), failed_attempts = 0, locked_until = NULL,
              totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL, totp_recovery = NULL WHERE id = ?")
    ->execute([Passwords::hash($temporary), $user['id']]);
$db->prepare("INSERT INTO audit_log (actor_id, action, subject_type, subject_id) VALUES (NULL, 'password.reset_on_server', 'user', ?)")->execute([$user['id']]);

echo "{$user['full_name']} can sign in now:\n";
echo "  sign in with:        " . ($user['email'] ?: $user['employee_no']) . "\n";
echo "  temporary password:  $temporary   (shown once; they choose their own at sign-in)\n";
if ($user['status'] !== 'active') {
    echo "  NOTE: this account is '{$user['status']}', so sign-in stays refused until it's active again.\n";
}
