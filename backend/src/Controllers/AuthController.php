<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Auth\Passwords;
use Sacco\Auth\Roles;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Tenant;

final class AuthController
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;

    /** GET /api/sacco?code= - the SACCO's name and branding for the login page */
    public function sacco(Request $request): never
    {
        $code = $request->string('code');
        $sacco = $code !== '' ? Tenant::find($code) : Tenant::current();
        if (!$sacco || $sacco['status'] !== 'active') {
            Response::error('We couldn\'t find that SACCO. Check the code with your SACCO office.', 404);
        }
        Response::ok(Tenant::publicInfo($sacco));
    }

    /** GET /api/auth/me - who is signed in (user is null when nobody is) */
    public function me(Request $request): never
    {
        $user = Auth::user();
        $sacco = Tenant::current();
        Response::ok([
            'user' => $user ? self::present($user) : null,
            'sacco' => $sacco ? Tenant::publicInfo($sacco) : null,
            'csrf_token' => $user ? Auth::csrfToken() : null,
        ]);
    }

    /** POST /api/auth/login { sacco?, login, password } - login is an email or employee number */
    public function login(Request $request): never
    {
        $login = $request->string('login');
        $password = (string) $request->input('password', '');
        if ($login === '' || $password === '') {
            Response::error('Enter your email or employee number, and your password.', 422);
        }

        $code = $request->string('sacco');
        $sacco = $code !== '' ? Tenant::find($code) : Tenant::fromRequest();
        if (!$sacco) {
            Response::error('We couldn\'t find that SACCO. Check the code with your SACCO office.', 404);
        }
        if ($sacco['status'] !== 'active') {
            Response::error('This SACCO is not available right now. Please contact your SACCO office.', 403);
        }
        // Point this session at the SACCO's database; nobody is signed in until Auth::login below
        Auth::logout();
        Auth::startSession();
        $_SESSION['sacco'] = $sacco['code'];

        $db = Database::tenant();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ? OR employee_no = ? LIMIT 1');
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch() ?: null;

        if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $minutes = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
            self::logAttempt($login, $user, false, 'locked');
            Response::error("Too many wrong passwords. Try again in {$minutes} minute" . ($minutes === 1 ? '' : 's') . '.', 429);
        }

        // Check a dummy hash when there's no such user, so the reply takes as long either way
        $ok = password_verify($password, $user['password_hash'] ?? '$2y$10$RcphI0l3G1eTAalvvYK7/ulMOfdATNeOvFR3kQ7gQx2173uxZe8Vm');
        if (!$user || !$ok) {
            if ($user) {
                $failed = (int) $user['failed_attempts'] + 1;
                if ($failed >= self::MAX_FAILED_ATTEMPTS) {
                    $db->prepare('UPDATE users SET failed_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL ' . self::LOCK_MINUTES . ' MINUTE) WHERE id = ?')
                        ->execute([$user['id']]);
                } else {
                    $db->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')->execute([$failed, $user['id']]);
                }
            }
            self::logAttempt($login, $user, false, $user ? 'wrong_password' : 'unknown_login');
            Response::error('That email/employee number and password don\'t match.', 401);
        }

        if ($user['status'] !== 'active') {
            self::logAttempt($login, $user, false, 'inactive');
            Response::error('Your account is not active. Please contact your SACCO office.', 403);
        }

        $db->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')
            ->execute([$user['id']]);
        Auth::login($user, $sacco['code']);
        self::logAttempt($login, $user, true, null);

        $this->me($request);
    }

    /** POST /api/auth/logout */
    public function logout(Request $request): never
    {
        Auth::logout();
        Response::ok();
    }

    /** POST /api/auth/change-password { current_password, new_password } */
    public function changePassword(Request $request): never
    {
        $user = Auth::user();
        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('new_password', '');

        if (!password_verify($current, $user['password_hash'])) {
            Response::error('Your current password is not correct.', 422, ['current_password' => 'Not correct']);
        }

        $db = Database::tenant();
        $stmt = $db->prepare('SELECT password_hash FROM password_history WHERE user_id = ? ORDER BY id DESC LIMIT ' . Passwords::HISTORY);
        $stmt->execute([$user['id']]);
        $previous = array_merge([$user['password_hash']], $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $problem = Passwords::problem($new, $user, $previous);
        if ($problem) {
            Response::error($problem, 422, ['new_password' => $problem]);
        }

        Database::transaction(function (\PDO $db) use ($user, $new) {
            $db->prepare('INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)')
                ->execute([$user['id'], $user['password_hash']]);
            $db->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?')
                ->execute([Passwords::hash($new), $user['id']]);
        });
        Audit::log('password.changed', 'user', (int) $user['id']);

        session_regenerate_id(true);
        Auth::refresh();
        $this->me($request);
    }

    /** A user as the frontend sees them - never the password hash */
    public static function present(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'employee_no' => $user['employee_no'],
            'account_no' => $user['account_no'],
            'joined_on' => $user['joined_on'] ?? null,
            'phone' => $user['phone'],
            'role' => $user['role'],
            'role_label' => Roles::label($user['role']),
            'status' => $user['status'],
            'source' => $user['source'],
            'must_change_password' => (bool) $user['must_change_password'],
            'last_login_at' => $user['last_login_at'],
            'created_at' => $user['created_at'],
        ];
    }

    private static function logAttempt(string $login, ?array $user, bool $success, ?string $reason): void
    {
        Database::tenant()->prepare(
            'INSERT INTO login_log (user_id, login, success, reason, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['id'] ?? null,
            mb_substr($login, 0, 190),
            $success ? 1 : 0,
            $reason,
            $_SERVER['REMOTE_ADDR'] ?? null,
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }
}
