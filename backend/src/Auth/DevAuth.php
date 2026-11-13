<?php

declare(strict_types=1);

namespace Sacco\Auth;

use Sacco\Database;

/**
 * The developer logged in to the developer portal: the people who run this installation and
 * look after every SACCO on it. Developers live in the platform database, not in any SACCO, and
 * log in with their own session cookie (see public/index.php).
 */
final class DevAuth
{
    private static array|false|null $user = null;

    public static function user(): ?array
    {
        if (self::$user === null) {
            self::$user = false;
            if (isset($_SESSION['dev_id'])) {
                $stmt = Database::platform()->prepare('SELECT * FROM developers WHERE id = ?');
                $stmt->execute([$_SESSION['dev_id']]);
                $row = $stmt->fetch();
                if ($row && $row['status'] === 'active') {
                    self::$user = $row;
                } else {
                    Auth::logout();
                }
            }
        }
        return self::$user ?: null;
    }

    public static function id(): ?int
    {
        return self::user() ? (int) self::user()['id'] : null;
    }

    public static function login(array $developer): void
    {
        session_regenerate_id(true);
        $_SESSION['dev_id'] = (int) $developer['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::$user = null;
    }

    public static function refresh(): void
    {
        self::$user = null;
    }

    /** Writes to the platform's own log: every change a developer makes, to any SACCO */
    public static function log(string $action, ?int $saccoId = null, array $details = []): void
    {
        Database::platform()->prepare(
            'INSERT INTO platform_audit (developer_id, sacco_id, action, details, ip) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            self::id(),
            $saccoId,
            $action,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}
