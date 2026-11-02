<?php

declare(strict_types=1);

namespace Sacco;

use PDO;

/**
 * Database connections. There are two kinds:
 *
 * - the platform database (PLATFORM_DB_NAME): the list of SACCOs on this installation
 * - one database per SACCO: its members, balances, loans... Which one a request uses is decided
 *   by Tenant (the SACCO chosen at login).
 */
final class Database
{
    private static ?PDO $platform = null;
    private static ?PDO $tenant = null;
    private static ?string $tenantName = null;

    public static function connect(string $dbName): PDO
    {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST', '127.0.0.1'),
                (int) Config::get('DB_PORT', 3306),
                $dbName
            ),
            (string) Config::get('DB_USER', 'root'),
            (string) Config::get('DB_PASS', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        // Strict mode on purpose: money must never be silently truncated or rounded
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET time_zone = '" . (new \DateTime())->format('P') . "'");
        return $pdo;
    }

    public static function platform(): PDO
    {
        return self::$platform ??= self::connect((string) Config::get('PLATFORM_DB_NAME', 'sacco_platform'));
    }

    /** The current SACCO's database */
    public static function tenant(): PDO
    {
        $name = Tenant::databaseName();
        if (self::$tenant === null || self::$tenantName !== $name) {
            self::$tenant = self::connect($name);
            self::$tenantName = $name;
        }
        return self::$tenant;
    }

    /** Runs $work inside a transaction on the SACCO's database; all of it is saved, or none */
    public static function transaction(callable $work): mixed
    {
        $db = self::tenant();
        $db->beginTransaction();
        try {
            $result = $work($db);
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
