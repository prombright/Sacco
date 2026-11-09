<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Auth\Totp;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Tenant;

/** Turning two-step sign-in on and off for your own account */
final class TwoStepController
{
    /** GET /api/me/two-step */
    public function status(Request $request): never
    {
        $u = Auth::user();
        Response::ok([
            'enabled' => (bool) $u['totp_enabled_at'],
            'enabled_at' => $u['totp_enabled_at'],
            'recovery_left' => $u['totp_recovery'] ? count(json_decode($u['totp_recovery'], true)) : 0,
        ]);
    }

    /** POST /api/me/two-step/start - a new secret to scan; nothing changes until it is confirmed */
    public function start(Request $request): never
    {
        $u = Auth::user();
        if ($u['totp_enabled_at']) {
            Response::error('Two-step sign-in is already on.', 422);
        }
        $secret = Totp::secret();
        $_SESSION['totp_pending'] = $secret;
        $issuer = Tenant::current()['short_name'] ?: Tenant::current()['name'];
        $account = $u['email'] ?: $u['employee_no'] ?: $u['account_no'];
        Response::ok([
            'secret' => $secret,
            'uri' => 'otpauth://totp/' . rawurlencode("$issuer:$account") . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&period=30&digits=6&algorithm=SHA1',
        ]);
    }

    /** POST /api/me/two-step/confirm { code } - returns the recovery codes, shown once */
    public function confirm(Request $request): never
    {
        $secret = $_SESSION['totp_pending'] ?? null;
        if (!is_string($secret)) {
            Response::error('Start again: scan the code first.', 422);
        }
        $step = Totp::verify($secret, preg_replace('/\s+/', '', $request->string('code')));
        if ($step === null) {
            Response::error('That code isn\'t right. Check the time on your phone and enter the newest code.', 422, ['code' => 'Wrong']);
        }
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < 8; $i++) {
            $code = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
            $codes[] = $code;
            $hashes[] = password_hash($code, PASSWORD_DEFAULT);
        }
        Database::tenant()->prepare('UPDATE users SET totp_secret = ?, totp_enabled_at = NOW(), totp_last_step = ?, totp_recovery = ? WHERE id = ?')
            ->execute([$secret, $step, json_encode($hashes), Auth::id()]);
        unset($_SESSION['totp_pending']);
        Audit::log('two_step.enabled', 'user', Auth::id());
        Auth::refresh();
        Response::ok(['recovery_codes' => $codes]);
    }

    /** POST /api/me/two-step/disable { password } */
    public function disable(Request $request): never
    {
        $u = Auth::user();
        if (!password_verify((string) $request->input('password', ''), $u['password_hash'])) {
            Response::error('Your password is not correct.', 422, ['password' => 'Not correct']);
        }
        Database::tenant()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL, totp_recovery = NULL WHERE id = ?')->execute([$u['id']]);
        Audit::log('two_step.disabled', 'user', (int) $u['id']);
        Auth::refresh();
        Response::ok();
    }
}
