<?php

declare(strict_types=1);

/**
 * Adds a SACCO: its row in the platform database, its own database with all tables, and its
 * first super admin (who gets a temporary password, printed once, to change at first login).
 *
 *   php backend/bin/create-sacco.php --code=smacon --name="SMACON SACCO" --short=SMACON \
 *       --admin-name="Jane Doe" --admin-email=jane@example.com [--db=sacco] [--domain=]
 *
 * On cPanel, create the database (and give the MySQL user access to it) in cPanel first and pass
 * its full name with --db.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use Sacco\Support\SaccoSetup;

$opt = getopt('', ['code:', 'name:', 'short:', 'admin-name:', 'admin-email:', 'db:', 'domain:', 'currency:']);
try {
    $made = SaccoSetup::create([
        'code' => $opt['code'] ?? '',
        'name' => $opt['name'] ?? '',
        'short_name' => $opt['short'] ?? null,
        'domain' => $opt['domain'] ?? null,
        'db_name' => $opt['db'] ?? null,
        'currency' => $opt['currency'] ?? null,
        'admin_name' => $opt['admin-name'] ?? '',
        'admin_email' => $opt['admin-email'] ?? '',
    ], fn ($l) => print($l . PHP_EOL));
} catch (\InvalidArgumentException $e) {
    exit($e->getMessage() . "

Usage: php backend/bin/create-sacco.php --code=smacon --name=\"SMACON SACCO\" --admin-name=\"Jane Doe\" --admin-email=jane@example.com [--short=SMACON] [--db=sacco] [--domain=host] [--currency=UGX]
");
}

echo "
SACCO '{$opt['name']}' is ready (code: {$made['code']}).
";
echo "Super admin: {$made['admin_email']}
";
echo "Temporary password: {$made['temporary_password']}   (shown once - they must change it at first login)
";
