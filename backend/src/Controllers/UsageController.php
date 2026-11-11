<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Roles;
use Sacco\Config;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;

/** Who is using the system, when and on what; failed sign-ins; and how well it is protected */
final class UsageController
{
    private const AREAS = [
        'loan' => 'Loans', 'loan_product' => 'Loans', 'withdrawal' => 'Withdrawals', 'transaction' => 'Deposits & entries', 'payroll' => 'Payroll',
        'dividend' => 'Dividends', 'profit_share' => 'Dividends', 'investment' => 'Investments', 'cashbook' => 'Income & expenses', 'journal' => 'Journal entries',
        'transfer' => 'Transfers', 'membership_fee' => 'Membership and subscription', 'bank_rec' => 'Bank reconciliation', 'user' => 'Member records', 'users' => 'Member records',
        'next_of_kin' => 'Member records', 'member' => 'Member records', 'notice' => 'Notices', 'settings' => 'Rules', 'account' => 'Chart of accounts', 'password' => 'Passwords', 'two_step' => 'Two-step sign-in',
    ];

    /** GET /api/office/usage?days=30 */
    public function index(Request $request): never
    {
        $days = (int) $request->query('days', 30);
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $db = Database::tenant();
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $one = function (string $sql, array $args = []) use ($db) {
            $s = $db->prepare($sql);
            $s->execute($args);
            return $s->fetchColumn();
        };
        $all = function (string $sql, array $args = []) use ($db) {
            $s = $db->prepare($sql);
            $s->execute($args);
            return $s->fetchAll();
        };

        // Sign-ins and failures for every day of the period
        $byDay = [];
        foreach ($all('SELECT DATE(created_at) AS d, SUM(success = 1) AS ok, SUM(success = 0) AS failed FROM login_log WHERE created_at >= ? GROUP BY d', [$since]) as $r) {
            $byDay[$r['d']] = $r;
        }
        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $daily[] = ['date' => $d, 'ok' => (int) ($byDay[$d]['ok'] ?? 0), 'failed' => (int) ($byDay[$d]['failed'] ?? 0)];
        }

        $hours = array_fill(0, 24, 0);
        foreach ($all('SELECT HOUR(created_at) AS h, COUNT(*) AS n FROM login_log WHERE success = 1 AND created_at >= ? GROUP BY h', [$since]) as $r) {
            $hours[(int) $r['h']] = (int) $r['n'];
        }

        $devices = ['Phone' => 0, 'Tablet' => 0, 'Computer' => 0];
        foreach ($all('SELECT user_agent, COUNT(*) AS n FROM login_log WHERE success = 1 AND created_at >= ? GROUP BY user_agent', [$since]) as $r) {
            $ua = (string) $r['user_agent'];
            $kind = preg_match('/iPad|Tablet/i', $ua) ? 'Tablet' : (preg_match('/Mobile|Android|iPhone/i', $ua) ? 'Phone' : 'Computer');
            $devices[$kind] += (int) $r['n'];
        }

        $areas = [];
        foreach ($all("SELECT SUBSTRING_INDEX(action, '.', 1) AS area, COUNT(*) AS n FROM audit_log WHERE created_at >= ? GROUP BY area", [$since]) as $r) {
            $name = self::AREAS[$r['area']] ?? null;
            if ($name) {
                $areas[$name] = ($areas[$name] ?? 0) + (int) $r['n'];
            }
        }
        arsort($areas);

        $members = (int) $one("SELECT COUNT(*) FROM users WHERE role = 'member' AND status = 'active'");
        $active = (int) $one("SELECT COUNT(DISTINCT l.user_id) FROM login_log l JOIN users u ON u.id = l.user_id WHERE l.success = 1 AND l.created_at >= ? AND u.role = 'member' AND u.status = 'active'", [$since]);
        $never = (int) $one("SELECT COUNT(*) FROM users WHERE role = 'member' AND status = 'active' AND last_login_at IS NULL");
        $office = $all("SELECT id, full_name, email, role, status, last_login_at, must_change_password, totp_enabled_at FROM users WHERE role IN ('super_admin', 'admin') ORDER BY FIELD(role, 'super_admin', 'admin'), full_name");
        $activeOffice = array_filter($office, fn ($u) => $u['status'] === 'active');
        $supers = count(array_filter($activeOffice, fn ($u) => $u['role'] === Roles::SUPER_ADMIN));
        $withTwoStep = count(array_filter($activeOffice, fn ($u) => $u['totp_enabled_at']));
        $tempOffice = count(array_filter($activeOffice, fn ($u) => $u['must_change_password']));
        $lastBackup = $one("SELECT MAX(created_at) FROM audit_log WHERE action = 'backup.downloaded'") ?: null;
        $failed = (int) $one('SELECT COUNT(*) FROM login_log WHERE success = 0 AND created_at >= ?', [$since]);
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $check = fn (string $label, bool $ok, string $note) => compact('label', 'ok', 'note');

        Response::ok([
            'days' => $days,
            'totals' => [
                'members' => $members,
                'active_members' => $active,
                'never_signed_in' => $never,
                'sign_ins' => array_sum(array_column($daily, 'ok')),
                'failed' => $failed,
                'locked' => (int) $one('SELECT COUNT(*) FROM users WHERE locked_until > NOW()'),
                'actions' => (int) $one('SELECT COUNT(*) FROM audit_log WHERE created_at >= ?', [$since]),
            ],
            'daily' => $daily,
            'hours' => $hours,
            'devices' => $devices,
            'areas' => array_map(fn ($k, $v) => ['area' => $k, 'count' => $v], array_keys($areas), array_values($areas)),
            'recent' => $all('SELECT u.full_name, u.role, l.ip, l.user_agent, l.created_at FROM login_log l JOIN users u ON u.id = l.user_id WHERE l.success = 1 ORDER BY l.id DESC LIMIT 12'),
            'failures' => $all('SELECT login, ip, reason, COUNT(*) AS n, MAX(created_at) AS last_at FROM login_log WHERE success = 0 AND created_at >= ? GROUP BY login, ip, reason ORDER BY last_at DESC LIMIT 15', [$since]),
            'locked' => $all('SELECT id, full_name, locked_until FROM users WHERE locked_until > NOW() ORDER BY full_name'),
            'office' => array_map(fn ($u) => [
                'full_name' => $u['full_name'],
                'email' => $u['email'],
                'role' => Roles::label($u['role']),
                'status' => $u['status'],
                'last_login_at' => $u['last_login_at'],
                'two_step' => (bool) $u['totp_enabled_at'],
            ], $office),
            'checks' => [
                $check('The live-server setting is on', Config::isProduction(), Config::isProduction() ? 'Sign-in cookies only travel over HTTPS.' : 'APP_ENV is not "production" (fine on a test computer).'),
                $check('Error details are hidden', !Config::isDebug(), Config::isDebug() ? 'APP_DEBUG is on: turn it off on the live server.' : 'Visitors never see technical error messages.'),
                $check('The connection is encrypted', $https, $https ? 'This page came over HTTPS.' : 'This page came over plain HTTP (fine on a test computer).'),
                $check('One super admin', $supers === 1, "$supers active super admin" . ($supers === 1 ? '' : 's') . '.'),
                $check('Office accounts use two-step sign-in', $withTwoStep === count($activeOffice) && $activeOffice, "$withTwoStep of " . count($activeOffice) . ' have it on.'),
                $check('No office account is on a temporary password', $tempOffice === 0, $tempOffice ? "$tempOffice still to change it." : 'All have chosen their own.'),
                $check('A backup was downloaded in the last 30 days', $lastBackup && strtotime($lastBackup) > strtotime('-30 days'), $lastBackup ? 'Last: ' . date('j M Y', strtotime($lastBackup)) . '.' : 'None downloaded yet.'),
                $check('The developer portal is limited to known addresses', trim((string) Config::get('DEV_ALLOWED_IPS', '')) !== '', trim((string) Config::get('DEV_ALLOWED_IPS', '')) !== '' ? 'Only listed addresses can reach it.' : 'Optional: set DEV_ALLOWED_IPS on the server.'),
            ],
        ]);
    }

    /** POST /api/users/{id}/unlock - lets a locked-out account try again now */
    public function unlock(Request $request): never
    {
        $id = (int) $request->param('id');
        Database::tenant()->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$id]);
        Audit::log('user.unlocked', 'user', $id);
        Response::ok();
    }
}
