<?php

declare(strict_types=1);

/**
 * Backs up the platform database and every SACCO's database to backend/storage/backups/<date>/,
 * one gzipped .sql file each, and keeps the newest BACKUP_KEEP_DAYS days (default 30).
 *
 *   php backend/bin/backup.php
 *
 * Run it every night from cron (cPanel -> Cron Jobs), e.g. at 02:00:
 *   0 2 * * * /usr/local/bin/php /home/USER/sacco/backend/bin/backup.php >/dev/null 2>&1
 * Copy the backups off the server now and then (download them, or a second server).
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use Sacco\Config;
use Sacco\Database;

$dump = (string) Config::get('MYSQLDUMP_PATH', PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : 'mysqldump');
$root = __DIR__ . '/../storage/backups';
$dir = $root . '/' . date('Y-m-d_Hi');
if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    exit("Can't create $dir\n");
}

$databases = [(string) Config::get('PLATFORM_DB_NAME', 'sacco')];
foreach (Database::platform()->query('SELECT db_name FROM saccos') as $row) {
    $databases[] = $row['db_name'];
}
// With one database for everything, it's backed up once
$databases = array_values(array_unique($databases));

// The password goes in the environment, never on the command line where other users could see it
putenv('MYSQL_PWD=' . Config::get('DB_PASS', ''));
$failed = 0;
foreach ($databases as $db) {
    $file = "$dir/$db.sql.gz";
    $cmd = sprintf(
        '%s --single-transaction --routines --no-tablespaces -h %s -P %d -u %s %s',
        escapeshellarg($dump),
        escapeshellarg((string) Config::get('DB_HOST', '127.0.0.1')),
        (int) Config::get('DB_PORT', 3306),
        escapeshellarg((string) Config::get('DB_USER', 'root')),
        escapeshellarg($db)
    );
    $out = gzopen($file, 'wb9');
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        echo "FAILED $db: can't run mysqldump ($dump)\n";
        $failed++;
        continue;
    }
    while (!feof($pipes[1])) {
        gzwrite($out, (string) fread($pipes[1], 1 << 16));
    }
    gzclose($out);
    $errors = stream_get_contents($pipes[2]);
    if (proc_close($proc) !== 0) {
        echo "FAILED $db: " . trim((string) $errors) . "\n";
        @unlink($file);
        $failed++;
    } else {
        echo "OK $db (" . round(filesize($file) / 1024) . " KB)\n";
    }
}
putenv('MYSQL_PWD');

// Keep only the newest days
$keep = (int) Config::get('BACKUP_KEEP_DAYS', 30);
$folders = glob($root . '/*', GLOB_ONLYDIR) ?: [];
rsort($folders);
foreach (array_slice($folders, $keep) as $old) {
    array_map('unlink', glob("$old/*") ?: []);
    rmdir($old);
}

exit($failed ? 1 : 0);
