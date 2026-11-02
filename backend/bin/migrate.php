<?php

declare(strict_types=1);

/**
 * Brings every database up to date: the platform database, then each SACCO's database.
 *
 *   php backend/bin/migrate.php
 *
 * Run it after every pull - a coworker's new tables only exist once this has run.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use Sacco\Config;
use Sacco\Database;
use Sacco\Support\Migrator;

$say = fn (string $line) => print($line . PHP_EOL);

$platform = (string) Config::get('PLATFORM_DB_NAME', 'sacco_platform');
Migrator::ensureDatabase($platform);
$say("Platform ($platform)");
Migrator::run($platform, 'platform', $say) ?: $say('  up to date');

foreach (Database::platform()->query('SELECT code, db_name FROM saccos ORDER BY id') as $sacco) {
    $say("SACCO {$sacco['code']} ({$sacco['db_name']})");
    Migrator::run($sacco['db_name'], 'tenant', $say) ?: $say('  up to date');
}
