<?php

declare(strict_types=1);

namespace Sacco\Support;

use Sacco\Config;
use Sacco\Database;
use Sacco\Tenant;

/**
 * A copy of the SACCO's data off the server, every night: the whole database in a zip locked with
 * a password (AES-256), emailed to the address(es) the super admin set on the backup page. Without
 * the password the file can't be opened, so an email that goes astray gives nothing away. The zip
 * opens with 7-Zip or WinRAR (Windows' own zip can't open AES).
 *
 * It runs on the first visit of each day, like the loan reminders, or from bin/daily.php.
 */
final class OffsiteBackup
{
    /** Emails carry about 20 MB at most; the zipped copy must stay under this */
    private const MAX_BYTES = 15 * 1024 * 1024;

    public static function emails(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', Settings::get('backup_emails')))));
    }

    public static function ready(): bool
    {
        return self::emails() && strlen(Settings::get('backup_password')) >= 12 && self::canLock();
    }

    /** The server can make an AES-locked zip */
    public static function canLock(): bool
    {
        return class_exists(\ZipArchive::class) && defined('ZipArchive::EM_AES_256');
    }

    /** Once a day, when set up; a failure is recorded on the backup page, never shown to the visitor */
    public static function runIfDue(): void
    {
        if (!self::ready() || Settings::get('backup_run_on') === date('Y-m-d')) {
            return;
        }
        self::save('backup_run_on', date('Y-m-d'));
        try {
            self::send();
        } catch (\Throwable $e) {
            error_log('[sacco] off-site backup failed: ' . $e->getMessage());
        }
    }

    /** Makes the locked copy and emails it now; returns where things stand */
    public static function send(): array
    {
        try {
            if (!self::canLock()) {
                throw new \RuntimeException('This server can\'t make a password-locked zip (PHP zip with AES is missing).');
            }
            $sacco = Tenant::current();
            $sql = Backup::sql();
            $name = $sacco['code'] . '-backup-' . date('Y-m-d') . '.sql';
            $tmp = tempnam(sys_get_temp_dir(), 'sbk');
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('The zip couldn\'t be made.');
            }
            $zip->addFromString($name, $sql);
            $zip->setEncryptionName($name, \ZipArchive::EM_AES_256, Settings::get('backup_password'));
            $zip->close();
            $data = (string) file_get_contents($tmp);
            @unlink($tmp);
            if (strlen($data) > self::MAX_BYTES) {
                throw new \RuntimeException('The copy is ' . round(strlen($data) / 1048576, 1) . ' MB, too big to email. Download it from this page instead.');
            }
            $file = $name . '.zip';
            $size = round(strlen($data) / 1048576, 2) . ' MB';
            foreach (self::emails() as $to) {
                $body = "Attached is tonight's copy of {$sacco['name']}'s data ($size), locked with the backup password.\n\n"
                    . "Keep this email: it is a copy you can restore from if the server is ever lost. Open it with 7-Zip or WinRAR and the backup password. "
                    . "It holds members' personal details and balances, so don't forward it.";
                [$html, $text] = Outbox::render("{$sacco['name']}: nightly backup", $body, '/admin/backup', 'colleague', 'Open the backup page');
                Mailer::send($to, '', "{$sacco['name']} backup " . date('j M Y'), $html, $text, [['name' => $file, 'type' => 'application/zip', 'data' => $data]]);
            }
            self::save('backup_last_sent', date('Y-m-d H:i:s') . ' · ' . $size . ' · ' . implode(', ', self::emails()));
            self::save('backup_last_error', '');
        } catch (\Throwable $e) {
            self::save('backup_last_error', date('Y-m-d H:i') . ': ' . $e->getMessage());
            throw $e;
        }
        return self::status();
    }

    public static function status(): array
    {
        return [
            'emails' => implode(', ', self::emails()),
            'password_set' => strlen(Settings::get('backup_password')) >= 12,
            'can_lock' => self::canLock(),
            'mail_working' => Mailer::enabled(),
            'last_sent' => Settings::get('backup_last_sent') ?: null,
            'last_error' => Settings::get('backup_last_error') ?: null,
        ];
    }

    public static function save(string $key, string $value): void
    {
        Database::tenant()->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$key, $value]);
        Settings::forget();
    }
}
