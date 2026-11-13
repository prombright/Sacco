<?php

declare(strict_types=1);

/**
 * Adds a developer: someone who logs in to the developer portal (/devportal) and looks after
 * every SACCO on this installation. Prints a temporary password, to change at first login.
 *
 *   php backend/bin/create-developer.php --name="Jane Doe" --email=jane@example.com
 *   php backend/bin/create-developer.php --name="Jane Doe" --email=jane@example.com --password='Chosen2026'
 *
 * Run it again for an existing email to reset that developer's password. With --password the
 * developer keeps that password (not temporary); put one with ! in single quotes.
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

$opt = getopt('', ['name:', 'email:', 'password:']);
$name = trim($opt['name'] ?? '');
$email = strtolower(trim($opt['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Usage: php backend/bin/create-developer.php --name=\"Jane Doe\" --email=jane@example.com
");
}

$platform = (string) Config::get('PLATFORM_DB_NAME', 'sacco');
Migrator::ensureDatabase($platform);
Migrator::run($platform, 'platform');

$chosen = $opt['password'] ?? null;
if ($chosen !== null && (strlen($chosen) < Passwords::MIN_LENGTH || !preg_match('/[a-z]/i', $chosen) || !preg_match('/\d/', $chosen))) {
    exit('The password needs at least ' . Passwords::MIN_LENGTH . " characters, with letters and numbers.\n");
}
$temporary = $chosen ?? Passwords::temporary();
$mustChange = $chosen === null ? 1 : 0;
$db = Database::platform();
$existing = $db->prepare('SELECT id FROM developers WHERE email = ?');
$existing->execute([$email]);
if ($existing->fetchColumn()) {
    $db->prepare("UPDATE developers SET password_hash = ?, must_change_password = ?, failed_attempts = 0, locked_until = NULL, status = 'active' WHERE email = ?")
        ->execute([Passwords::hash($temporary), $mustChange, $email]);
    echo "Password reset for $email
";
} else {
    if ($name === '') {
        exit("Give the developer's name with --name.
");
    }
    $db->prepare('INSERT INTO developers (full_name, email, password_hash, must_change_password) VALUES (?, ?, ?, ?)')
        ->execute([$name, $email, Passwords::hash($temporary), $mustChange]);
    echo "Developer $name ($email) added
";
}
echo $chosen === null
    ? "Temporary password: $temporary   (shown once - change it at first login to /devportal)\n"
    : "Password set to the one you gave. Log in at /devportal; change it any time with the key icon.\n";
