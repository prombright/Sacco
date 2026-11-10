<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Config;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Support\Migrator;
use Sacco\Tenant;

/** A copy of the SACCO's whole database, downloaded by a super admin to keep off the server */
final class BackupController
{
    /** GET /api/backup - a gzipped .sql file */
    public function download(Request $request): never
    {
        $sacco = Tenant::current();
        $db = $sacco['db_name'];
        $dump = (string) Config::get('MYSQLDUMP_PATH', PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : 'mysqldump');
        // With one database for everything, the platform's tables (other SACCOs, developer
        // accounts) are left out: this download is the SACCO's own data
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
            Response::error('The backup couldn\'t be made on this server. Use the nightly backups instead.', 500);
        }
        $sql = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0 || $sql === '' || $sql === false) {
            error_log('[sacco] backup download failed: ' . $err);
            Response::error('The backup couldn\'t be made on this server. Use the nightly backups instead.', 500);
        }
        Audit::log('backup.downloaded', null, null, ['size' => strlen($sql)]);

        $file = $sacco['code'] . '-backup-' . date('Y-m-d_Hi') . '.sql.gz';
        $gz = gzencode($sql, 9);
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . strlen($gz));
        header('Cache-Control: no-store');
        echo $gz;
        exit;
    }
}
