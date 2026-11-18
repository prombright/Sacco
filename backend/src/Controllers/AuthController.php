<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Auth\OfficeAccess;
use Sacco\Auth\Passwords;
use Sacco\Auth\Roles;
use Sacco\Auth\Throttle;
use Sacco\Auth\Totp;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Tenant;

final class AuthController
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;
    private const TWO_STEP_SECONDS = 300;

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

    /** GET /api/auth/me - who is logged in (user is null when nobody is) */
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

        Throttle::check();
        $code = $request->string('sacco');
        $sacco = $code !== '' ? Tenant::find($code) : Tenant::fromRequest();
        if (!$sacco) {
            Response::error('We couldn\'t find that SACCO. Check the code with your SACCO office.', 404);
        }
        if ($sacco['status'] !== 'active') {
            Response::error('This SACCO is not available right now. Please contact your SACCO office.', 403);
        }
        // Point this session at the SACCO's database; nobody is logged in until Auth::login below
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
            Throttle::fail();
            self::logAttempt($login, $user, false, $user ? 'wrong_password' : 'unknown_login');
            Response::error('That email/employee number and password don\'t match.', 401);
        }

        if ($user['status'] !== 'active') {
            self::logAttempt($login, $user, false, 'inactive');
            Response::error('Your account is not active. Please contact your SACCO office.', 403);
        }

        // The password was right; with two-step login on, the code comes next
        if ($user['totp_enabled_at']) {
            session_regenerate_id(true);
            $_SESSION['two_step'] = ['user' => (int) $user['id'], 'login' => $login, 'at' => time(), 'tries' => 0, 'remember' => (bool) $request->input('remember')];
            Response::ok(['two_step' => true]);
        }

        $this->finishSignIn($request, $user, $login, $sacco['code'], (bool) $request->input('remember'));
    }

    /** POST /api/auth/two-step { code } - the app's six digits, or a recovery code */
    public function twoStep(Request $request): never
    {
        $pending = $_SESSION['two_step'] ?? null;
        if (!is_array($pending) || time() - $pending['at'] > self::TWO_STEP_SECONDS || !isset($_SESSION['sacco'])) {
            unset($_SESSION['two_step']);
            Response::error('That took too long. Please log in again.', 422, ['restart' => 'yes']);
        }
        Throttle::check();
        $db = Database::tenant();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$pending['user']]);
        $user = $stmt->fetch();
        if (!$user || $user['status'] !== 'active' || !$user['totp_secret']) {
            unset($_SESSION['two_step']);
            Response::error('Please log in again.', 422, ['restart' => 'yes']);
        }

        $code = strtoupper(preg_replace('/\s+/', '', $request->string('code')));
        $step = Totp::verify($user['totp_secret'], $code, $user['totp_last_step'] !== null ? (int) $user['totp_last_step'] : null);
        if ($step !== null) {
            $db->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?')->execute([$step, $user['id']]);
        } elseif (!$this->useRecoveryCode($db, $user, $code)) {
            Throttle::fail();
            self::logAttempt($pending['login'], $user, false, 'wrong_two_step_code');
            $_SESSION['two_step']['tries'] = $pending['tries'] + 1;
            if ($_SESSION['two_step']['tries'] >= 5) {
                unset($_SESSION['two_step']);
                Response::error('Too many wrong codes. Please log in again.', 422, ['restart' => 'yes']);
            }
            Response::error('That code isn\'t right. Enter the newest code from your app, or a recovery code.', 422, ['code' => 'Wrong']);
        }
        unset($_SESSION['two_step']);
        $this->finishSignIn($request, $user, $pending['login'], (string) $_SESSION['sacco'], !empty($pending['remember']));
    }

    private function useRecoveryCode(\PDO $db, array $user, string $code): bool
    {
        if (!preg_match('/^[0-9A-F]{4}-[0-9A-F]{4}$/', $code)) {
            return false;
        }
        $hashes = json_decode((string) $user['totp_recovery'], true) ?: [];
        foreach ($hashes as $i => $hash) {
            if (password_verify($code, $hash)) {
                unset($hashes[$i]);
                $db->prepare('UPDATE users SET totp_recovery = ? WHERE id = ?')->execute([json_encode(array_values($hashes)), $user['id']]);
                return true;
            }
        }
        return false;
    }

    private function finishSignIn(Request $request, array $user, string $login, string $saccoCode, bool $remember = false): never
    {
        Database::tenant()->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')
            ->execute([$user['id']]);
        Auth::login($user, $saccoCode);
        // Only members' own devices are remembered; office accounts always time out
        if ($remember && $user['role'] === Roles::MEMBER) {
            Auth::remember();
        }
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
            'national_id' => $user['national_id'] ?? null,
            'date_of_birth' => $user['date_of_birth'] ?? null,
            'gender' => $user['gender'] ?? null,
            'department' => $user['department'] ?? null,
            'position' => $user['position'] ?? null,
            'address' => $user['address'] ?? null,
            'photo_url' => !empty($user['photo']) ? "api/users/{$user['id']}/photo?v={$user['photo']}" : null,
            'phone' => $user['phone'],
            'role' => $user['role'],
            'role_label' => Roles::label($user['role']),
            'permissions' => Roles::permissions($user),
            'status' => $user['status'],
            'source' => $user['source'],
            // Not asked of a member account opened with the round switch from the office
            'must_change_password' => (bool) $user['must_change_password'] && !((int) $user['id'] === Auth::id() && !empty($_SESSION['office_id'])),
            'two_step' => (bool) ($user['totp_enabled_at'] ?? false),
            'last_login_at' => $user['last_login_at'],
            // Members see a short welcome once, on whichever device they first use
            'welcomed' => !empty($user['welcomed_at']),
            'linked_member_id' => !empty($user['linked_member_id']) ? (int) $user['linked_member_id'] : null,
            // Members: the office role they hold through office access, if any
            'office_role' => $user['role'] === Roles::MEMBER ? OfficeAccess::roleOf(Database::tenant(), (int) $user['id']) : null,
            'office_via_member' => !empty($user['office_via_member']),
            // The other account this person may switch to now (only for whoever is logged in)
            'switch_to' => self::switchTarget($user),
            'created_at' => $user['created_at'],
        ];
    }

    /**
     * Where the logged-in person may switch: from an office account to its linked member account;
     * from that member account back to the office, but only when this login started in the office
     * (so a member password never opens an office account).
     */
    private static function switchTarget(array $user): ?array
    {
        if ((int) $user['id'] !== Auth::id() || Roles::isMain($user)) {
            return null;
        }
        $db = Database::tenant();
        // An office login reaches a member account only when the developer has linked the two
        if (Roles::allows($user['role'], [Roles::ADMIN]) && !empty($user['linked_member_id'])) {
            $s = $db->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'member' AND status = 'active'");
            $s->execute([$user['linked_member_id']]);
            return ($m = $s->fetch()) ? ['to' => 'member', 'label' => 'My member account', 'full_name' => $m['full_name']] : null;
        }
        if ($user['role'] === Roles::MEMBER) {
            // Back to the office: when this login started there, or when the office access was given
            // to this member (it has no login of its own; the member's login opens it)
            $s = $db->prepare("SELECT id, full_name, office_via_member FROM users WHERE linked_member_id = ? AND role <> 'member' AND status = 'active'");
            $s->execute([$user['id']]);
            $o = $s->fetch();
            if ($o && ((int) $o['office_via_member'] === 1 || (int) ($_SESSION['office_id'] ?? 0) === (int) $o['id'])) {
                return ['to' => 'office', 'label' => empty($_SESSION['office_id']) ? 'Switch to the office' : 'Back to the office', 'full_name' => $o['full_name'], 'id' => (int) $o['id']];
            }
        }
        return null;
    }

    /** POST /api/auth/switch - between an office account and its owner's member account */
    public function switchAccount(Request $request): never
    {
        $user = Auth::user();
        $target = self::switchTarget($user) ?? Response::error('There is no other account to switch to.', 422);
        $db = Database::tenant();
        $sacco = $_SESSION['sacco'];
        $remember = !empty($_SESSION['remember']);
        if ($target['to'] === 'member') {
            $s = $db->prepare('SELECT * FROM users WHERE id = ?');
            $s->execute([$user['linked_member_id']]);
            $next = $s->fetch();
            $officeId = (int) $user['id'];
            Auth::login($next, $sacco);
            $_SESSION['office_id'] = $officeId;
        } else {
            $s = $db->prepare('SELECT * FROM users WHERE id = ?');
            $s->execute([$target['id']]);
            $next = $s->fetch();
            unset($_SESSION['office_id']);
            Auth::login($next, $sacco);
            // The office never stays logged in for weeks, even from a phone that keeps the member in
            $remember = false;
        }
        if ($remember) {
            $_SESSION['remember'] = true;
        }
        Audit::log('auth.switched', 'user', (int) $next['id'], ['from' => (int) $user['id'], 'to' => $target['to']]);
        Response::ok(['user' => self::present(Auth::user()), 'csrf_token' => Auth::csrfToken()]);
    }

    /** POST /api/me/welcomed - the first-login welcome was seen (or skipped) */
    public function welcomed(Request $request): never
    {
        Database::tenant()->prepare('UPDATE users SET welcomed_at = COALESCE(welcomed_at, NOW()) WHERE id = ?')->execute([Auth::id()]);
        Response::ok();
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
