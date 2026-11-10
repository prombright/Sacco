<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use PDO;
use Sacco\Auth\Passwords;
use Sacco\Auth\Throttle;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Support\Mailer;
use Sacco\Support\Notify;
use Sacco\Support\Outbox;
use Sacco\Tenant;

/**
 * "Forgot password": a link to the account's email, which works once, for an hour. The reply is
 * the same whether or not the email belongs to an account, so nobody can use it to find out who
 * is a member. Members without an email ask the office for a temporary password.
 */
final class PasswordResetController
{
    private const MINUTES = 60;
    private const PER_IP_PER_HOUR = 10;
    private const PER_USER_PER_HOUR = 3;

    /** POST /api/auth/forgot { email, sacco? } */
    public function forgot(Request $request): never
    {
        $email = strtolower(trim($request->string('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Enter the email address on your account.', 422, ['email' => 'Not an email address']);
        }
        Throttle::check();
        $sacco = self::sacco($request);
        $ip = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);

        Tenant::actAs($sacco, function (PDO $db) use ($email, $ip, $sacco) {
            $recent = $db->prepare('SELECT COUNT(*) FROM password_resets WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
            $recent->execute([$ip]);
            if ((int) $recent->fetchColumn() >= self::PER_IP_PER_HOUR) {
                Response::error('Too many reset requests from this connection. Please wait an hour, or ask your SACCO office.', 429);
            }
            $stmt = $db->prepare("SELECT * FROM users WHERE LOWER(email) = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user) {
                // Counted too, so guessing emails is slowed down the same way
                $db->prepare('INSERT INTO password_resets (user_id, token_hash, ip, expires_at, used_at) VALUES (NULL, ?, ?, NOW(), NOW())')
                    ->execute([hash('sha256', random_bytes(32)), $ip]);
                return;
            }
            $mine = $db->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND used_at IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
            $mine->execute([$user['id']]);
            if ((int) $mine->fetchColumn() >= self::PER_USER_PER_HOUR) {
                return;
            }
            $token = bin2hex(random_bytes(32));
            $db->prepare('INSERT INTO password_resets (user_id, token_hash, ip, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . self::MINUTES . ' MINUTE))')
                ->execute([$user['id'], hash('sha256', $token), $ip]);
            $link = Mailer::appUrl() . '/reset-password?token=' . $token . '&sacco=' . rawurlencode($sacco['code']);
            [$html, $text] = Outbox::render(
                'Choose a new password',
                "Someone (we hope you) asked to reset the password for your {$sacco['name']} account.\n\nThe button below lets you choose a new one. It works once, for the next " . self::MINUTES . " minutes.\n\nIf you didn't ask for this, ignore this email: your password stays as it is.",
                $link, $user['full_name'], 'Choose a new password'
            );
            try {
                Mailer::send($user['email'], $user['full_name'], 'Reset your ' . $sacco['name'] . ' password', $html, $text);
            } catch (\Throwable $e) {
                error_log('[sacco] password reset email failed: ' . $e->getMessage());
            }
            Audit::log('password.reset_requested', 'user', (int) $user['id'], [], (int) $user['id']);
        });

        Response::ok(['message' => 'If that email is on an account, a link to choose a new password is on its way. It works for ' . self::MINUTES . ' minutes; check your spam folder too. No email on your account? Ask your SACCO office to reset your password.']);
    }

    /** GET /api/auth/reset?token=&sacco= - is the link still good? */
    public function check(Request $request): never
    {
        $sacco = self::sacco($request);
        $user = Tenant::actAs($sacco, fn (PDO $db) => self::userFor($db, (string) $request->query('token', '')));
        Response::ok(['valid' => (bool) $user, 'first_name' => $user ? explode(' ', $user['full_name'])[0] : null]);
    }

    /** POST /api/auth/reset { token, sacco?, password } */
    public function reset(Request $request): never
    {
        $sacco = self::sacco($request);
        $token = $request->string('token');
        $new = (string) $request->input('password', '');
        Throttle::check();

        Tenant::actAs($sacco, function (PDO $db) use ($token, $new) {
            $user = self::userFor($db, $token);
            if (!$user) {
                Throttle::fail();
                Response::error('This link has expired or was already used. Ask for a new one.', 410);
            }
            $stmt = $db->prepare('SELECT password_hash FROM password_history WHERE user_id = ? ORDER BY id DESC LIMIT ' . Passwords::HISTORY);
            $stmt->execute([$user['id']]);
            $problem = Passwords::problem($new, $user, array_merge([$user['password_hash']], $stmt->fetchAll(PDO::FETCH_COLUMN)));
            if ($problem) {
                Response::error($problem, 422, ['password' => $problem]);
            }
            $db->beginTransaction();
            $db->prepare('INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)')->execute([$user['id'], $user['password_hash']]);
            // password_changed_at signs out every other session of this account
            $db->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = NOW(), failed_attempts = 0, locked_until = NULL WHERE id = ?')
                ->execute([Passwords::hash($new), $user['id']]);
            // This link and any other unused ones stop working
            $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$user['id']]);
            $db->commit();
            Audit::log('password.reset', 'user', (int) $user['id'], [], (int) $user['id']);
            Notify::$system = true;
            Notify::member((int) $user['id'], 'account_update', 'Your password was changed',
                'Your password was changed with a reset link sent to your email. If this wasn\'t you, contact your SACCO office straight away.', '/profile');
            Notify::$system = false;
        });
        Response::ok(['message' => 'Your password is changed. Sign in with the new one.']);
    }

    private static function userFor(PDO $db, string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $stmt = $db->prepare(
            "SELECT u.* FROM password_resets r JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND u.status = 'active'"
        );
        $stmt->execute([hash('sha256', $token)]);
        return $stmt->fetch() ?: null;
    }

    private static function sacco(Request $request): array
    {
        $code = trim((string) ($request->input('sacco') ?? $request->query('sacco', '')));
        $sacco = $code !== '' ? Tenant::find($code) : Tenant::fromRequest();
        if (!$sacco || $sacco['status'] !== 'active') {
            Response::error('We couldn\'t find that SACCO. Check the code with your SACCO office.', 404);
        }
        return $sacco;
    }
}
