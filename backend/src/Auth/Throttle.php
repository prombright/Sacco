<?php

declare(strict_types=1);

namespace Sacco\Auth;

use Sacco\Database;
use Sacco\Http\Response;

/**
 * Slows password guessing from one computer, across all accounts (each account also locks by
 * itself after repeated wrong passwords). The limit is generous because a whole school can share
 * one internet address. If the table is missing the check steps aside rather than block login.
 */
final class Throttle
{
    private const MAX_FAILURES = 30;
    private const WINDOW_MINUTES = 10;

    public static function check(): void
    {
        try {
            $stmt = Database::platform()->prepare('SELECT failures, window_start > DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE) AS recent FROM login_throttle WHERE ip = ?');
            $stmt->execute([self::ip()]);
            $row = $stmt->fetch();
        } catch (\PDOException $e) {
            error_log('[sacco] login throttle unavailable: ' . $e->getMessage());
            return;
        }
        if ($row && $row['recent'] && (int) $row['failures'] >= self::MAX_FAILURES) {
            Response::error('Too many failed logins from this connection. Please wait ' . self::WINDOW_MINUTES . ' minutes and try again.', 429);
        }
    }

    public static function fail(): void
    {
        try {
            Database::platform()->prepare(
                'INSERT INTO login_throttle (ip, failures, window_start) VALUES (?, 1, NOW())
                 ON DUPLICATE KEY UPDATE
                    failures = IF(window_start > DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE), failures + 1, 1),
                    window_start = IF(window_start > DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE), window_start, NOW())'
            )->execute([self::ip()]);
        } catch (\PDOException $e) {
            error_log('[sacco] login throttle unavailable: ' . $e->getMessage());
        }
    }

    private static function ip(): string
    {
        return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
    }
}
