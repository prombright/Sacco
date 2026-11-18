<?php

declare(strict_types=1);

namespace Sacco\Support;

use Sacco\Database;

/** The SACCO's own rules from its settings table (interest rate, share price...) */
final class Settings
{
    private static ?array $all = null;

    public static function get(string $key, string $default = ''): string
    {
        if (self::$all === null) {
            self::$all = Database::tenant()->query('SELECT `key`, `value` FROM settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
        }
        return self::$all[$key] ?? $default;
    }

    /** Saves one setting (for the system's own bookkeeping, e.g. the last month interest was earned) */
    public static function set(string $key, string $value): void
    {
        Database::tenant()->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$key, $value]);
        if (self::$all !== null) {
            self::$all[$key] = $value;
        }
    }

    public static function forget(): void
    {
        self::$all = null;
    }
}
