<?php

declare(strict_types=1);

namespace Sacco\Support;

use PDO;
use Sacco\Config;
use Sacco\Database;

/**
 * Applies the numbered .sql files in database/platform/ and database/tenant/ that a database
 * hasn't had yet, recording each in its schema_migrations table. Files are never edited once
 * committed - a change to the schema is a new, higher-numbered file.
 */
final class Migrator
{
    private const DIR = __DIR__ . '/../../database';

    /** Creates the database if this MySQL user is allowed to (on cPanel, create it there first) */
    public static function ensureDatabase(string $name): void
    {
        $server = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', Config::get('DB_HOST', '127.0.0.1'), (int) Config::get('DB_PORT', 3306)),
            (string) Config::get('DB_USER', 'root'),
            (string) Config::get('DB_PASS', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid database name: $name");
        }
        $exists = $server->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $exists->execute([$name]);
        if (!$exists->fetchColumn()) {
            $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    }

    /**
     * @param 'platform'|'tenant' $kind
     * @return string[] the files applied
     */
    public static function run(string $dbName, string $kind, ?callable $say = null): array
    {
        $db = Database::connect($dbName);
        $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            filename VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $done = $db->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

        $files = glob(self::DIR . "/$kind/*.sql");
        sort($files, SORT_NATURAL);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            // MySQL commits table changes as it goes, so a failed file is reported with the
            // statement that failed; fix it and run again (already-created tables need dropping)
            foreach (self::statements((string) file_get_contents($file)) as $sql) {
                try {
                    $db->exec($sql);
                } catch (\PDOException $e) {
                    throw new \RuntimeException("$name failed: " . $e->getMessage() . "\n\n" . $sql, 0, $e);
                }
            }
            $db->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$name]);
            $applied[] = $name;
            $say && $say("  applied $name");
        }
        return $applied;
    }

    /** Splits a file on the semicolons that end a line, ignoring -- comments */
    private static function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        return array_values(array_filter(array_map('trim', preg_split('/;\s*$/m', $sql))));
    }
}
