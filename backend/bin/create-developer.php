<?php

declare(strict_types=1);

/**
 * Adds a developer: someone who signs in to the developer portal (/devportal) and looks after
 * every SACCO on this installation. Prints a temporary password, to change at first sign-in.
 *
 *   php backend/bin/create-developer.php --name="Jane Doe" --email=jane@example.com
 *
 * Run it again for an existing email to reset that developer's password.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.
");
}

require __DIR__ . '/../bootstrap.php';

use Sacco\Auth\Passwords;
use Sacco\Config;
use Sacco\Database;
use Sacco\Support\Migrator;

$opt = getopt('', ['name:', 'email:']);
$name = trim($opt['name'] ?? '');
$email = strtolower(trim($opt['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Usage: php backend/bin/create-developer.php --name=\"Jane Doe\" --email=jane@example.com
");
}

$platform = (string) Config::get('PLATFORM_DB_NAME', 'sacco');
Migrator::ensureDatabase($platform);
Migrator::run($platform, 'platform');

$temporary = Passwords::temporary();
$db = Database::platform();
$existing = $db->prepare('SELECT id FROM developers WHERE email = ?');
$existing->execute([$email]);
if ($existing->fetchColumn()) {
    $db->prepare("UPDATE developers SET password_hash = ?, must_change_password = 1, failed_attempts = 0, locked_until = NULL, status = 'active' WHERE email = ?")
        ->execute([Passwords::hash($temporary), $email]);
    echo "Password reset for $email
";
} else {
    if ($name === '') {
        exit("Give the developer's name with --name.
");
    }
    $db->prepare('INSERT INTO developers (full_name, email, password_hash) VALUES (?, ?, ?)')
        ->execute([$name, $email, Passwords::hash($temporary)]);
    echo "Developer $name ($email) added
";
}
echo "Temporary password: $temporary   (shown once - change it at first sign-in to /devportal)
";
