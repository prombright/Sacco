<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Config;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Support\OffsiteBackup;
use Sacco\Support\Backup;
use Sacco\Support\Migrator;
use Sacco\Tenant;

/** A copy of the SACCO's whole database, downloaded by a super admin to keep off the server */
final class BackupController
{
    /** GET /api/backup - a gzipped .sql file */
    public function download(Request $request): never
    {
        $sacco = Tenant::current();
        try {
            $sql = Backup::sql();
        } catch (\RuntimeException $e) {
            error_log('[sacco] backup download failed: ' . $e->getMessage());
            Response::error('The backup couldn\'t be made on this server. Ask the developer to check it.', 500);
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

    /** GET /api/backup/offsite (super admin) - where the nightly emailed copy stands */
    public function offsite(Request $request): never
    {
        Response::ok(OffsiteBackup::status());
    }

    /** PATCH /api/backup/offsite { emails, password? } (super admin) */
    public function saveOffsite(Request $request): never
    {
        $emails = array_values(array_filter(array_map('trim', explode(',', $request->string('emails')))));
        foreach ($emails as $e) {
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                Response::error("\"$e\" isn't an email address.", 422, ['emails' => 'Invalid']);
            }
        }
        if (count($emails) > 5) {
            Response::error('Up to 5 addresses.', 422, ['emails' => 'Too many']);
        }
        $password = (string) $request->input('password', '');
        if ($password !== '' && strlen($password) < 12) {
            Response::error('Use a password of at least 12 characters: it is all that protects the copy.', 422, ['password' => 'Too short']);
        }
        OffsiteBackup::save('backup_emails', implode(', ', $emails));
        if ($password !== '') {
            OffsiteBackup::save('backup_password', $password);
        }
        Audit::log('backup.offsite_set', null, null, ['emails' => implode(', ', $emails), 'password_changed' => $password !== '']);
        Response::ok(OffsiteBackup::status());
    }

    /** POST /api/backup/offsite/send (super admin) - send a copy now, to check it works */
    public function sendOffsite(Request $request): never
    {
        if (!OffsiteBackup::ready()) {
            Response::error('Set the email address(es) and a backup password first.', 422);
        }
        try {
            $status = OffsiteBackup::send();
        } catch (\Throwable $e) {
            Response::error('The copy couldn\'t be sent: ' . $e->getMessage(), 500);
        }
        Audit::log('backup.offsite_sent', null, null, ['to' => $status['emails']]);
        Response::ok($status);
    }
}
