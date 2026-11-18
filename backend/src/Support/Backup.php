<?php

declare(strict_types=1);

namespace Sacco\Support;

use Sacco\Config;
use Sacco\Tenant;

/** The SACCO's own data as one .sql file (members, balances, loans, the books, the audit log) */
final class Backup
{
    /** @throws \RuntimeException when mysqldump can't make it */
    public static function sql(): string
    {
        $db = Tenant::current()['db_name'];
        $dump = (string) Config::get('MYSQLDUMP_PATH', PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : 'mysqldump');
        // With one database for everything, the platform's tables (other SACCOs, developer
        // accounts) are left out: this is the SACCO's own data
        $skip = Migrator::sharesPlatform($db)
            ? implode(' ', array_map(fn ($t) => '--ignore-table=' . escapeshellarg("$db.$t"), Migrator::PLATFORM_TABLES))
            : '';
        $cmd = sprintf(
            '%s --single-transaction --routines --no-tablespaces -h %s -P %d -u %s %s %s',
            escapeshellarg($dump),
            escapeshellarg((string) Config::get('DB_HOST', '127.0.0.1')),
            (int) Config::get('DB_PORT', 3306),
            escapeshellarg((string) Config::get('DB_USER', 'root')),
            $skip,
            escapeshellarg($db)
        );
        // The password goes in the environment, never on the command line
        $env = array_merge(getenv(), ['MYSQL_PWD' => (string) Config::get('DB_PASS', '')]);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('mysqldump couldn\'t be started on this server.');
        }
        $sql = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0 || $sql === '' || $sql === false) {
            throw new \RuntimeException('mysqldump failed: ' . trim((string) $err));
        }
        return $sql;
    }
}
