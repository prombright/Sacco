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

    public static function forget(): void
    {
        self::$all = null;
    }
}
