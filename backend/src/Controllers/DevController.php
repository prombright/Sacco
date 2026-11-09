<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Auth\DevAuth;
use Sacco\Auth\Passwords;
use Sacco\Auth\Roles;
use Sacco\Auth\Throttle;
use Sacco\Config;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Activity;
use Sacco\Support\Audit;
use Sacco\Support\Migrator;
use Sacco\Support\Money;
use Sacco\Support\Notify;
use Sacco\Support\SaccoSetup;
use Sacco\Tenant;

/**
 * The developer portal: the people who run this installation see every SACCO on it - its
 * figures, its people, everything that happened - and look after it: its details and look, its
 * office accounts, passwords, system updates, and new SACCOs.
 *
 * Every change is written to the platform's log and to the SACCO's own audit log, and the SACCO's
 * office accounts are told about it, so nothing is changed behind their backs.
 */
final class DevController
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;

    // --- Signing in ----------------------------------------------------------------------------

    /** GET /api/dev/me */
    public function me(Request $request): never
    {
        $d = DevAuth::user();
        Response::ok([
            'developer' => $d ? self::presentDeveloper($d) : null,
            'csrf_token' => $d ? Auth::csrfToken() : null,
        ]);
    }

    /** POST /api/dev/login { email, password } */
    public function login(Request $request): never
    {
        $email = strtolower($request->string('email'));
        $password = (string) $request->input('password', '');
        if ($email === '' || $password === '') {
            Response::error('Enter your email and password.', 422);
        }
        Throttle::check();
        $db = Database::platform();
        $stmt = $db->prepare('SELECT * FROM developers WHERE email = ?');
        $stmt->execute([$email]);
        $d = $stmt->fetch() ?: null;

        if ($d && $d['locked_until'] && strtotime($d['locked_until']) > time()) {
            Response::error('Too many wrong passwords. Try again in a few minutes.', 429);
        }
        $ok = password_verify($password, $d['password_hash'] ?? '$2y$10$RcphI0l3G1eTAalvvYK7/ulMOfdATNeOvFR3kQ7gQx2173uxZe8Vm');
        if (!$d || !$ok) {
            Throttle::fail();
            if ($d) {
                $failed = (int) $d['failed_attempts'] + 1;
                $failed >= self::MAX_FAILED_ATTEMPTS
                    ? $db->prepare('UPDATE developers SET failed_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL ' . self::LOCK_MINUTES . ' MINUTE) WHERE id = ?')->execute([$d['id']])
                    : $db->prepare('UPDATE developers SET failed_attempts = ? WHERE id = ?')->execute([$failed, $d['id']]);
            }
            Response::error('That email and password don\'t match.', 401);
        }
        if ($d['status'] !== 'active') {
            Response::error('This developer account is suspended.', 403);
        }
        $db->prepare('UPDATE developers SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')->execute([$d['id']]);
        DevAuth::login($d);
        DevAuth::log('developer.signed_in');
        $this->me($request);
    }

    /** POST /api/dev/logout */
    public function logout(Request $request): never
    {
        Auth::logout();
        Response::ok();
    }

    /** POST /api/dev/change-password { current_password, new_password } */
    public function changePassword(Request $request): never
    {
        $d = DevAuth::user();
        if (!password_verify((string) $request->input('current_password', ''), $d['password_hash'])) {
            Response::error('Your current password is not correct.', 422, ['current_password' => 'Not correct']);
        }
        $new = (string) $request->input('new_password', '');
        $problem = Passwords::problem($new, ['email' => $d['email'], 'employee_no' => null], [$d['password_hash']]);
        if ($problem) {
            Response::error($problem, 422, ['new_password' => $problem]);
        }
        Database::platform()->prepare('UPDATE developers SET password_hash = ?, must_change_password = 0 WHERE id = ?')
            ->execute([Passwords::hash($new), $d['id']]);
        DevAuth::log('developer.password_changed');
        session_regenerate_id(true);
        DevAuth::refresh();
        $this->me($request);
    }

    // --- Every SACCO --------------------------------------------------------------------------

    /** GET /api/dev/overview - every SACCO with its figures and health, and the totals */
    public function overview(Request $request): never
    {
        $saccos = Database::platform()->query('SELECT * FROM saccos ORDER BY name')->fetchAll();
        $rows = [];
        $totals = ['members' => 0, 'savings' => 0, 'shares' => 0, 'loans_outstanding' => 0, 'arrears' => 0, 'waiting' => 0];
        $trend = [];
        foreach ($saccos as $s) {
            $row = self::presentSacco($s);
            try {
                $stats = Tenant::actAs($s, fn (\PDO $db) => self::stats($db));
                $row['stats'] = $stats;
                $row['pending_updates'] = count(Migrator::pending($s['db_name'], 'tenant'));
                foreach (['members', 'savings', 'shares', 'loans_outstanding', 'arrears'] as $k) {
                    $totals[$k] += $k === 'members' ? $stats[$k] : Money::fromDecimal($stats[$k]);
                }
                $totals['waiting'] += $stats['waiting_total'];
                foreach ($stats['funds'] as $i => $p) {
                    $trend[$i] = ['month' => $p['month'], 'value' => ($trend[$i]['value'] ?? 0) + Money::fromDecimal($p['value'])];
                }
                $row['error'] = null;
            } catch (\Throwable $e) {
                // A SACCO whose database can't be read still shows, with the problem
                $row['stats'] = null;
                $row['pending_updates'] = null;
                $row['error'] = $e->getMessage();
            }
            $rows[] = $row;
        }
        foreach (['savings', 'shares', 'loans_outstanding', 'arrears'] as $k) {
            $totals[$k] = Money::toDecimal($totals[$k]);
        }
        Response::ok([
            'saccos' => $rows,
            'totals' => $totals + ['saccos' => count($saccos), 'active_saccos' => count(array_filter($saccos, fn ($s) => $s['status'] === 'active'))],
            'funds' => array_map(fn ($p) => ['month' => $p['month'], 'value' => Money::toDecimal($p['value'])], array_values($trend)),
            'pending_platform_updates' => count(Migrator::pending((string) Config::get('PLATFORM_DB_NAME', 'sacco_platform'), 'platform')),
            'recent' => $this->platformLog(null, 12),
        ]);
    }

    /** GET /api/dev/saccos/{id} - one SACCO in full */
    public function show(Request $request): never
    {
        $s = $this->sacco((int) $request->param('id'));
        $data = Tenant::actAs($s, function (\PDO $db) {
            $office = $db->query(
                "SELECT id, full_name, email, employee_no, role, status, must_change_password, last_login_at, created_at
                 FROM users WHERE role IN ('super_admin', 'admin') ORDER BY FIELD(role, 'super_admin', 'admin'), full_name"
            )->fetchAll();
            $rules = [];
            foreach ($db->query('SELECT `key`, `value`, updated_at FROM settings ORDER BY `key`')->fetchAll() as $r) {
                $rules[] = ['key' => $r['key'], 'label' => SettingsController::label($r['key']), 'value' => $r['value'], 'updated_at' => $r['updated_at'] ?? null];
            }
            $byStatus = $db->query('SELECT status, COUNT(*) AS n, SUM(amount) AS amount FROM loans GROUP BY status')->fetchAll(\PDO::FETCH_UNIQUE);
            $failed = $db->query('SELECT login, reason, ip, created_at FROM login_log WHERE success = 0 ORDER BY id DESC LIMIT 10')->fetchAll();
            return [
                'stats' => self::stats($db),
                'office' => array_map(fn ($u) => [
                    'id' => (int) $u['id'],
                    'full_name' => $u['full_name'],
                    'email' => $u['email'],
                    'employee_no' => $u['employee_no'],
                    'role' => $u['role'],
                    'role_label' => Roles::label($u['role']),
                    'status' => $u['status'],
                    'must_change_password' => (bool) $u['must_change_password'],
                    'last_login_at' => $u['last_login_at'],
                ], $office),
                'rules' => $rules,
                'loans_by_status' => array_map(fn ($r) => ['count' => (int) $r['n'], 'amount' => $r['amount']], $byStatus),
                'failed_sign_ins' => $failed,
            ];
        });
        Response::ok($data + [
            'sacco' => self::presentSacco($s) + ['db_name' => $s['db_name']],
            'pending_updates' => Migrator::pending($s['db_name'], 'tenant'),
            'portal_log' => $this->platformLog((int) $s['id'], 30),
        ]);
    }

    /** GET /api/dev/saccos/{id}/members?q=&page= - everyone in the SACCO, with their balances */
    public function members(Request $request): never
    {
        $s = $this->sacco((int) $request->param('id'));
        $q = $request->string('q');
        $page = max(1, (int) $request->query('page', 1));
        $per = 25;
        $data = Tenant::actAs($s, function (\PDO $db) use ($q, $page, $per) {
            $where = '1 = 1';
            $args = [];
            if ($q !== '') {
                $where = '(u.full_name LIKE ? OR u.email LIKE ? OR u.employee_no LIKE ? OR u.account_no LIKE ?)';
                $args = array_fill(0, 4, "%$q%");
            }
            $count = $db->prepare("SELECT COUNT(*) FROM users u WHERE $where");
            $count->execute($args);
            $stmt = $db->prepare(
                "SELECT u.id, u.full_name, u.email, u.employee_no, u.account_no, u.role, u.status, u.last_login_at, u.joined_on,
                    (SELECT balance_after FROM transactions t WHERE t.user_id = u.id AND t.account = 'savings' ORDER BY t.id DESC LIMIT 1) AS savings,
                    (SELECT balance_after FROM transactions t WHERE t.user_id = u.id AND t.account = 'shares' ORDER BY t.id DESC LIMIT 1) AS shares,
                    (SELECT COUNT(*) FROM loans l WHERE l.user_id = u.id AND l.status = 'active') AS active_loans
                 FROM users u WHERE $where ORDER BY u.full_name LIMIT $per OFFSET " . (($page - 1) * $per)
            );
            $stmt->execute($args);
            return ['members' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
        });
        Response::ok($data + ['page' => $page, 'per_page' => $per]);
    }

    /** GET /api/dev/saccos/{id}/activity?page= - the SACCO's whole audit log, in words */
    public function activity(Request $request): never
    {
        $s = $this->sacco((int) $request->param('id'));
        $page = max(1, (int) $request->query('page', 1));
        $per = 40;
        $data = Tenant::actAs($s, function (\PDO $db) use ($page, $per) {
            $rows = $db->query(
                'SELECT a.*, u.full_name AS actor, u.role AS actor_role FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id
                 ORDER BY a.id DESC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per)
            )->fetchAll();
            return array_map(fn ($r) => [
                'id' => (int) $r['id'],
                'actor' => $r['actor'] ?? (str_starts_with($r['action'], 'platform.') ? 'System provider' : 'System'),
                'actor_role' => $r['actor_role'],
                'text' => Activity::describe($r),
                'details' => Activity::explain($r),
                'ip' => $r['ip'],
                'at' => $r['created_at'],
            ], $rows);
        });
        Response::ok(['activity' => array_slice($data, 0, $per), 'has_more' => count($data) > $per, 'page' => $page]);
    }

    /** PATCH /api/dev/saccos/{id} - the SACCO's details, look and status */
    public function update(Request $request): never
    {
        $s = $this->sacco((int) $request->param('id'));
        $fields = [
            'name' => fn ($v) => $v !== '' && mb_strlen($v) <= 160 ? $v : null,
            'short_name' => fn ($v) => mb_strlen($v) <= 40 ? ($v ?: '') : null,
            'domain' => fn ($v) => $v === '' || preg_match('/^[a-z0-9.-]{3,190}$/', strtolower($v)) ? strtolower($v) : null,
            'slogan' => fn ($v) => mb_strlen($v) <= 160 ? $v : null,
            'about' => fn ($v) => mb_strlen($v) <= 255 ? $v : null,
            'brand_color' => fn ($v) => preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null,
            'currency' => fn ($v) => preg_match('/^[A-Za-z]{3}$/', $v) ? strtoupper($v) : null,
            'login_image' => fn ($v) => $v === '' || preg_match('#^[A-Za-z0-9/_.-]{1,255}$#', $v) ? $v : null,
            'logo_url' => fn ($v) => mb_strlen($v) <= 255 ? $v : null,
            'status' => fn ($v) => in_array($v, ['active', 'paused'], true) ? $v : null,
        ];
        $changes = [];
        $errors = [];
        foreach ($request->json() as $key => $value) {
            if (!isset($fields[$key]) || !is_scalar($value)) {
                continue;
            }
            $clean = $fields[$key](trim((string) $value));
            if ($clean === null) {
                $errors[$key] = 'Not a valid value.';
                continue;
            }
            $before = (string) ($s[$key] ?? '');
            if ($clean !== $before) {
                $changes[$key] = ['from' => $before, 'to' => $clean];
            }
        }
        if ($errors) {
            Response::error('Some values aren\'t valid.', 422, $errors);
        }
        if (!$changes) {
            $this->show($request);
        }
        $sets = implode(', ', array_map(fn ($k) => "`$k` = ?", array_keys($changes)));
        $values = array_map(fn ($c, $k) => $c['to'] === '' && $k !== 'name' ? null : $c['to'], $changes, array_keys($changes));
        try {
            Database::platform()->prepare("UPDATE saccos SET $sets WHERE id = ?")->execute([...$values, $s['id']]);
        } catch (\PDOException $e) {
            Response::error('That web address is already used by another SACCO.', 422, ['domain' => 'Already used']);
        }
        Tenant::forget();
        $this->record($s, 'platform.sacco_updated', null, null, ['changes' => $changes], 'The system provider changed the SACCO\'s details');
        $this->show($request);
    }

    /** POST /api/dev/saccos - adds a SACCO with its own database and first super admin */
    public function create(Request $request): never
    {
        try {
            $made = SaccoSetup::create([
                'code' => $request->string('code'),
                'name' => $request->string('name'),
                'short_name' => $request->string('short_name'),
                'domain' => $request->string('domain'),
                'db_name' => $request->string('db_name'),
                'currency' => $request->string('currency'),
                'admin_name' => $request->string('admin_name'),
                'admin_email' => $request->string('admin_email'),
            ]);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        }
        DevAuth::log('platform.sacco_created', $made['id'], ['code' => $made['code'], 'db' => $made['db_name'], 'admin' => $made['admin_email']]);
        Response::ok($made, 201);
    }

    /** POST /api/dev/saccos/{id}/office-accounts { full_name, email, role } - a new admin or super admin */
    public function addOfficeAccount(Request $request): never
    {
        $s = $this->sacco((int) $request->param('id'));
        $name = mb_substr($request->string('full_name'), 0, 160);
        $email = strtolower($request->string('email'));
        $role = $request->string('role');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, [Roles::SUPER_ADMIN, Roles::ADMIN], true)) {
            Response::error('Enter a name, a valid email, and choose super admin or admin.', 422);
        }
        $temporary = Passwords::temporary();
        $id = Tenant::actAs($s, function (\PDO $db) use ($name, $email, $role, $temporary) {
            $taken = $db->prepare('SELECT 1 FROM users WHERE email = ?');
            $taken->execute([$email]);
            if ($taken->fetchColumn()) {
                Response::error('Someone in this SACCO already uses that email.', 422, ['email' => 'Already used']);
            }
            $db->prepare('INSERT INTO users (full_name, email, role, password_hash, must_change_password) VALUES (?, ?, ?, ?, 1)')
                ->execute([$name, $email, $role, Passwords::hash($temporary)]);
            $id = (int) $db->lastInsertId();
            $db->prepare('UPDATE users SET account_no = ? WHERE id = ?')->execute(['M' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
            return $id;
        });
        $this->record($s, 'platform.office_account_added', 'user', $id, ['role' => $role, 'name' => $name], 'The system provider added ' . ($role === Roles::SUPER_ADMIN ? 'a super admin' : 'an admin') . ": $name");
        Response::ok(['user_id' => $id, 'email' => $email, 'temporary_password' => $temporary], 201);
    }

    /** POST /api/dev/saccos/{id}/users/{user}/reset-password - a new temporary password */
    public function resetPassword(Request $request): never
    {
        $s = $this->sacco((int) $request->param('id'));
        $userId = (int) $request->param('user');
        $temporary = Passwords::temporary();
        $user = Tenant::actAs($s, function (\PDO $db) use ($userId, $temporary) {
            $u = $this->user($db, $userId);
            $db->prepare('UPDATE users SET password_hash = ?, must_change_password = 1, password_changed_at = NOW(), totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL, totp_recovery = NULL, failed_attempts = 0, locked_until = NULL WHERE id = ?')
                ->execute([Passwords::hash($temporary), $userId]);
            return $u;
        });
        $this->record($s, 'platform.password_reset', 'user', $userId, ['name' => $user['full_name']], "The system provider reset {$user['full_name']}'s password", $userId);
        Response::ok(['temporary_password' => $temporary, 'login' => $user['email'] ?: $user['employee_no']]);
    }

    /** POST /api/dev/saccos/{id}/users/{user}/status { status: active|suspended } */
    public function setUserStatus(Request $request): never
    {
        $s = $this->sacco((int) $request->param('id'));
        $userId = (int) $request->param('user');
        $status = $request->string('status');
        if (!in_array($status, ['active', 'suspended'], true)) {
            Response::error('Choose active or suspended.', 422);
        }
        $user = Tenant::actAs($s, function (\PDO $db) use ($userId, $status) {
            $u = $this->user($db, $userId);
            $db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $userId]);
            return $u;
        });
        $this->record($s, 'platform.user_status', 'user', $userId, ['name' => $user['full_name'], 'changes' => ['status' => ['from' => $user['status'], 'to' => $status]]],
            "The system provider " . ($status === 'active' ? 'reactivated' : 'suspended') . " {$user['full_name']}'s account", $userId);
        $this->show($request);
    }

    /** POST /api/dev/saccos/{id}/update-system - applies the SACCO's pending database updates */
    public function updateSystem(Request $request): never
    {
        $s = $this->sacco((int) $request->param('id'));
        try {
            $applied = Migrator::run($s['db_name'], 'tenant');
        } catch (\Throwable $e) {
            DevAuth::log('platform.migration_failed', (int) $s['id'], ['error' => $e->getMessage()]);
            Response::error('An update failed: ' . $e->getMessage(), 500);
        }
        if ($applied) {
            DevAuth::log('platform.migrated', (int) $s['id'], ['files' => $applied]);
        }
        Response::ok(['applied' => $applied]);
    }

    /** GET /api/dev/log - everything done in the developer portal */
    public function log(Request $request): never
    {
        Response::ok(['log' => $this->platformLog(null, 200)]);
    }

    // --- Helpers -------------------------------------------------------------------------------

    /** A SACCO's headline figures (run inside Tenant::actAs) */
    private static function stats(\PDO $db): array
    {
        $one = function (string $sql) use ($db) {
            return $db->query($sql)->fetchColumn();
        };
        $outstanding = 0;
        $arrears = 0;
        $late = 0;
        $active = $db->query("SELECT id FROM loans WHERE status = 'active'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($active as $loanId) {
            $p = Loans::position($db, (int) $loanId);
            $outstanding += $p['total_left'];
            if ($p['overdue'] > 0) {
                $arrears += $p['overdue'];
                $late++;
            }
        }
        $waiting = OfficeController::waiting();
        return [
            'members' => (int) $one("SELECT COUNT(*) FROM users WHERE role = 'member' AND status = 'active'"),
            'office_accounts' => (int) $one("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'super_admin') AND status = 'active'"),
            'savings' => Money::toDecimal(Money::fromDecimal($one("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account = 'savings'"))),
            'shares' => Money::toDecimal(Money::fromDecimal($one("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account = 'shares'"))),
            'loans_outstanding' => Money::toDecimal($outstanding),
            'active_loans' => count($active),
            'arrears' => Money::toDecimal($arrears),
            'loans_in_arrears' => $late,
            'waiting' => $waiting,
            'waiting_total' => $waiting['loans_to_approve'] + $waiting['loans_to_pay_out'] + $waiting['withdrawals_to_approve'] + $waiting['withdrawals_to_pay'],
            'signed_in_30_days' => (int) $one('SELECT COUNT(*) FROM users WHERE last_login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'),
            'last_activity' => $one('SELECT MAX(created_at) FROM audit_log') ?: null,
            'funds' => OfficeController::fundsTrend($db),
        ];
    }

    /** Writes a change to the platform log and the SACCO's audit log, and tells its office */
    private function record(array $s, string $action, ?string $subjectType, ?int $subjectId, array $details, string $title, ?int $alsoTell = null): void
    {
        $developer = DevAuth::user();
        DevAuth::log($action, (int) $s['id'], $details + ['subject' => $subjectId]);
        Tenant::actAs($s, function () use ($action, $subjectType, $subjectId, $details, $title, $developer, $alsoTell) {
            $details['by'] = 'System provider (' . $developer['full_name'] . ')';
            Audit::log($action, $subjectType, $subjectId, $details);
            $row = ['action' => $action, 'details' => $details, 'subject_type' => $subjectType, 'subject_id' => $subjectId];
            Audit::tellOffice($title, $row);
            // The member whose account it was hears about it too, if they aren't office staff
            if ($alsoTell) {
                $u = Database::tenant()->prepare("SELECT role FROM users WHERE id = ?");
                $u->execute([$alsoTell]);
                if ($u->fetchColumn() === Roles::MEMBER) {
                    Notify::member($alsoTell, 'account_update', $title, 'For help, contact your SACCO office.', '/profile');
                }
            }
        });
    }

    private function platformLog(?int $saccoId, int $limit): array
    {
        $sql = 'SELECT p.*, d.full_name AS developer, s.name AS sacco FROM platform_audit p
                LEFT JOIN developers d ON d.id = p.developer_id LEFT JOIN saccos s ON s.id = p.sacco_id';
        $stmt = $saccoId
            ? Database::platform()->prepare("$sql WHERE p.sacco_id = ? ORDER BY p.id DESC LIMIT $limit")
            : Database::platform()->prepare("$sql ORDER BY p.id DESC LIMIT $limit");
        $stmt->execute($saccoId ? [$saccoId] : []);
        return array_map(function ($r) {
            $d = $r['details'] ? json_decode($r['details'], true) : [];
            return [
                'developer' => $r['developer'] ?? 'Someone',
                'sacco' => $r['sacco'],
                'action' => $r['action'],
                'text' => match ($r['action']) {
                    'developer.signed_in' => 'signed in',
                    'developer.password_changed' => 'changed their password',
                    'platform.sacco_created' => 'set up a new SACCO (' . ($d['code'] ?? '') . ')',
                    'platform.migrated' => 'applied ' . count($d['files'] ?? []) . ' system update(s)',
                    'platform.migration_failed' => 'tried a system update, which failed',
                    default => Activity::describe(['action' => $r['action'], 'details' => $d]),
                },
                'details' => Activity::explain(['action' => $r['action'], 'details' => $d]),
                'ip' => $r['ip'],
                'at' => $r['created_at'],
            ];
        }, $stmt->fetchAll());
    }

    private function sacco(int $id): array
    {
        $stmt = Database::platform()->prepare('SELECT * FROM saccos WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: Response::error('SACCO not found.', 404);
    }

    private function user(\PDO $db, int $id): array
    {
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: Response::error('That account doesn\'t exist in this SACCO.', 404);
    }

    private static function presentSacco(array $s): array
    {
        return Tenant::publicInfo($s) + [
            'id' => (int) $s['id'],
            'domain' => $s['domain'],
            'about' => $s['about'] ?? null,
            'status' => $s['status'],
            'created_at' => $s['created_at'],
        ];
    }

    private static function presentDeveloper(array $d): array
    {
        return [
            'id' => (int) $d['id'],
            'full_name' => $d['full_name'],
            'email' => $d['email'],
            'must_change_password' => (bool) $d['must_change_password'],
            'last_login_at' => $d['last_login_at'],
        ];
    }
}
