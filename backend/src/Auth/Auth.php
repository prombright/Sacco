<?php

declare(strict_types=1);

namespace Sacco\Auth;

use Sacco\Config;
use Sacco\Database;

/**
 * The signed-in user. The session holds only the user's id and SACCO; the user row is re-read
 * on every request so a suspended account or a changed role takes effect at once.
 */
final class Auth
{
    private static array|false|null $user = null;

    /** The developer portal uses its own cookie, so it never mixes with a SACCO sign-in */
    public static function startSession(string $name = 'sacco_session'): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => Config::isProduction(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Signed out after IDLE_MINUTES without activity
        $idle = (int) Config::get('SESSION_IDLE_MINUTES', 30) * 60;
        if ((isset($_SESSION['user_id']) || isset($_SESSION['dev_id'])) && isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > $idle) {
            self::logout();
            session_start();
        }
        $_SESSION['last_seen'] = time();
    }

    public static function user(): ?array
    {
        if (self::$user === null) {
            self::$user = false;
            if (isset($_SESSION['user_id'], $_SESSION['sacco'])) {
                $stmt = Database::tenant()->prepare('SELECT * FROM users WHERE id = ?');
                $stmt->execute([$_SESSION['user_id']]);
                $row = $stmt->fetch();
                $stale = $row && $row['password_changed_at'] && strtotime($row['password_changed_at']) > (int) ($_SESSION['auth_at'] ?? 0);
                if ($row && $row['status'] === 'active' && !$stale) {
                    self::$user = $row;
                } else {
                    self::logout();
                }
            }
        }
        return self::$user ?: null;
    }

    public static function id(): ?int
    {
        return self::user() ? (int) self::user()['id'] : null;
    }

    public static function login(array $user, string $saccoCode): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['sacco'] = $saccoCode;
        $_SESSION['auth_at'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::$user = null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$user = false;
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    /** Forget the cached row so the next user() call re-reads it (after the user's row changes) */
    public static function refresh(): void
    {
        self::$user = null;
        $_SESSION['auth_at'] = time();
    }
}
