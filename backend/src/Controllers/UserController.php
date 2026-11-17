<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Auth\OfficeAccess;
use Sacco\Auth\Passwords;
use Sacco\Auth\Roles;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Notify;
use Sacco\Support\Audit;

/**
 * Users = everyone who can log in: members, admins and super admins. Admins manage members;
 * only a super admin manages admins and super admins (Roles::canManage).
 */
final class UserController
{
    private const STATUSES = ['active', 'suspended', 'left'];

    /** GET /api/users?q=&role=&status=&page= */
    public function index(Request $request): never
    {
        $where = [];
        $args = [];
        $q = $request->string('q');
        if ($q !== '') {
            $where[] = '(full_name LIKE ? OR email LIKE ? OR employee_no LIKE ? OR account_no LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if (in_array($request->string('role'), Roles::ALL, true)) {
            $where[] = 'role = ?';
            $args[] = $request->string('role');
        }
        if (in_array($request->string('status'), self::STATUSES, true)) {
            $where[] = 'status = ?';
            $args[] = $request->string('status');
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $perPage = 50;
        $page = max(1, (int) $request->query('page', 1));
        $db = Database::tenant();

        $count = $db->prepare("SELECT COUNT(*) FROM users $sqlWhere");
        $count->execute($args);

        $latest = fn (string $account) => "(SELECT t.balance_after FROM transactions t WHERE t.user_id = users.id AND t.account = '$account' ORDER BY t.id DESC LIMIT 1)";
        $stmt = $db->prepare(
            "SELECT users.*, {$latest('savings')} AS savings_balance, {$latest('shares')} AS shares_balance
             FROM users $sqlWhere ORDER BY full_name LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
        );
        $stmt->execute($args);

        Response::ok([
            'users' => array_map(fn (array $u) => AuthController::present($u) + [
                'balances' => ['savings' => $u['savings_balance'] ?? '0.00', 'shares' => $u['shares_balance'] ?? '0.00'],
            ], $stmt->fetchAll()),
            'total' => (int) $count->fetchColumn(),
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /** POST /api/users - returns the new user and their one-time temporary password */
    public function store(Request $request): never
    {
        $data = $this->validated($request, null);
        if (!Roles::canManage(Auth::user()['role'], $data['role'])) {
            Response::error('Only a super admin can add admins.', 403);
        }
        $temporary = Passwords::temporary();

        $id = Database::transaction(function (\PDO $db) use ($data, $temporary) {
            $db->prepare(
                'INSERT INTO users (full_name, email, employee_no, account_no, joined_on, phone, national_id, date_of_birth, gender, department, position, address, role, status, source, password_hash, must_change_password, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'manual\', ?, 1, ?)'
            )->execute([
                $data['full_name'], $data['email'], $data['employee_no'], $data['account_no'], $data['joined_on'], $data['phone'],
                $data['national_id'], $data['date_of_birth'], $data['gender'], $data['department'], $data['position'], $data['address'],
                $data['role'], $data['status'], Passwords::hash($temporary), Auth::id(),
            ]);
            $id = (int) $db->lastInsertId();
            if ($data['account_no'] === null) {
                $db->prepare('UPDATE users SET account_no = ? WHERE id = ?')->execute([self::defaultAccountNo($id), $id]);
            }
            return $id;
        });
        Audit::log('user.created', 'user', $id, ['role' => $data['role']]);
        // A new office account for someone who already saves: linked to their member account when it's clearly them
        if ($data['role'] !== Roles::MEMBER) {
            OfficeAccess::autoLink(Database::tenant(), $this->find($id));
        }

        Response::ok(['user' => AuthController::present($this->find($id)), 'temporary_password' => $temporary], 201);
    }

    /** PATCH /api/users/{id} */
    public function update(Request $request): never
    {
        $user = $this->find((int) $request->param('id'));
        $actor = Auth::user();
        $data = $this->validated($request, $user);

        if (!Roles::canManage($actor['role'], $user['role']) || !Roles::canManage($actor['role'], $data['role'])) {
            Response::error('Only a super admin can change admins.', 403);
        }
        if ((int) $user['id'] === (int) $actor['id'] && ($data['role'] !== $user['role'] || $data['status'] !== 'active')) {
            Response::error('You can\'t change your own role or deactivate yourself. Ask another super admin.', 422);
        }
        if ($user['role'] === Roles::SUPER_ADMIN && ($data['role'] !== Roles::SUPER_ADMIN || $data['status'] !== 'active') && $this->activeSuperAdmins() <= 1) {
            Response::error('There must always be at least one active super admin.', 422);
        }

        // A member given an office role stays a member (their savings and their own login); office
        // access is added for the same person, reached with the round switch. Member again = taken away.
        $officeChange = null;
        if ($user['role'] === Roles::MEMBER) {
            $wanted = $data['role'];
            $data['role'] = Roles::MEMBER;
            $current = OfficeAccess::roleOf(Database::tenant(), (int) $user['id']);
            if ($wanted !== Roles::MEMBER && $wanted !== $current) {
                $officeChange = $wanted;
            } elseif ($wanted === Roles::MEMBER && $current !== null) {
                $office = OfficeAccess::officeOf(Database::tenant(), (int) $user['id']);
                if ((int) $office['id'] === (int) $actor['id']) {
                    Response::error('You can\'t take away your own office access. Ask another super admin.', 422);
                }
                if ($office['role'] === Roles::SUPER_ADMIN && $this->activeSuperAdmins() <= 1) {
                    Response::error('There must always be at least one active super admin.', 422);
                }
                $officeChange = 'none';
            }
        }

        Database::tenant()->prepare(
            'UPDATE users SET full_name = ?, email = ?, employee_no = ?, account_no = ?, joined_on = ?, phone = ?,
                national_id = ?, date_of_birth = ?, gender = ?, department = ?, position = ?, address = ?, role = ?, status = ? WHERE id = ?'
        )->execute([
            $data['full_name'], $data['email'], $data['employee_no'], $data['account_no'] ?? $user['account_no'],
            $data['joined_on'], $data['phone'],
            $data['national_id'], $data['date_of_birth'], $data['gender'], $data['department'], $data['position'], $data['address'],
            $data['role'], $data['status'], $user['id'],
        ]);

        if ($officeChange !== null) {
            $db = Database::tenant();
            if ($officeChange === 'none') {
                $office = OfficeAccess::revoke($db, $user);
                Audit::log('users.office_access', 'user', (int) $user['id'], ['role' => null, 'office' => (int) $office['id']]);
                Notify::member((int) $user['id'], 'account_update', 'Your office access has ended',
                    'You no longer have office access to the SACCO system. Your member account is just as it was.', '/');
            } else {
                $office = OfficeAccess::grant($db, $this->find((int) $user['id']), $officeChange, Auth::id());
                Audit::log('users.office_access', 'user', (int) $user['id'], ['role' => $officeChange, 'office' => (int) $office['id']]);
                Notify::member((int) $user['id'], 'account_update', 'You now have office access',
                    'You can now work in the SACCO office as ' . Roles::label($officeChange) . '. Log in as usual and tap the round switch at the top to go to the office, and again to come back to your own account.', '/');
            }
        }

        $changed = [];
        foreach (['full_name', 'email', 'employee_no', 'account_no', 'joined_on', 'phone', 'national_id', 'date_of_birth', 'gender', 'department', 'position', 'address', 'role', 'status'] as $field) {
            if (($data[$field] ?? $user[$field]) !== $user[$field]) {
                $changed[$field] = ['from' => $user[$field], 'to' => $data[$field]];
            }
        }
        if ($changed) {
            Audit::log('user.updated', 'user', (int) $user['id'], $changed);
            if ($user['role'] === Roles::MEMBER && (int) $user['id'] !== (int) $actor['id']) {
                $names = array_map(fn ($f) => str_replace(['_no', '_'], [' number', ' '], $f), array_keys($changed));
                Notify::member((int) $user['id'], 'account_update', 'Your account details were updated',
                    'The SACCO office changed your ' . implode(', ', $names) . '. If something looks wrong, contact the office.', '/profile');
            }
        }

        Response::ok(['user' => AuthController::present($this->find((int) $user['id']))]);
    }

    /** POST /api/users/{id}/reset-password - a new one-time temporary password; also unlocks the account */
    public function resetPassword(Request $request): never
    {
        $user = $this->find((int) $request->param('id'));
        if (!Roles::canManage(Auth::user()['role'], $user['role'])) {
            Response::error('Only a super admin can reset an admin\'s password.', 403);
        }
        $temporary = Passwords::temporary();
        Database::tenant()->prepare(
            'UPDATE users SET password_hash = ?, must_change_password = 1, password_changed_at = NOW(), totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL, totp_recovery = NULL, failed_attempts = 0, locked_until = NULL WHERE id = ?'
        )->execute([Passwords::hash($temporary), $user['id']]);
        Audit::log('user.password_reset', 'user', (int) $user['id']);
        if ($user['role'] === Roles::MEMBER) {
            Notify::member((int) $user['id'], 'account_update', 'Your password was reset',
                'The SACCO office gave you a new temporary password on ' . date('j M Y \a\t H:i') . ". If you didn't ask for this, contact the office.", '/profile');
        }

        Response::ok(['user' => AuthController::present($this->find((int) $user['id'])), 'temporary_password' => $temporary]);
    }

    private function find(int $id): array
    {
        $stmt = Database::tenant()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: Response::error('User not found.', 404);
    }

    private function activeSuperAdmins(): int
    {
        return (int) Database::tenant()->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND status = 'active'")->fetchColumn();
    }

    /** The fields from the request, checked; on update, missing fields keep their current value */
    private function validated(Request $request, ?array $existing): array
    {
        $body = $request->json();
        $value = function (string $field) use ($body, $existing): ?string {
            $v = array_key_exists($field, $body) ? $body[$field] : ($existing[$field] ?? null);
            $v = is_scalar($v) ? trim((string) $v) : null;
            return $v === '' ? null : $v;
        };

        $data = [
            'full_name' => $value('full_name'),
            'email' => $value('email') !== null ? strtolower($value('email')) : null,
            'employee_no' => $value('employee_no'),
            'account_no' => $value('account_no'),
            // Member since; blank means "from their first savings entry"
            'joined_on' => $value('joined_on'),
            'phone' => $value('phone'),
            'national_id' => $value('national_id') !== null ? strtoupper($value('national_id')) : null,
            'date_of_birth' => $value('date_of_birth'),
            'gender' => $value('gender'),
            'department' => $value('department'),
            'position' => $value('position'),
            'address' => $value('address'),
            'role' => $value('role') ?? Roles::MEMBER,
            'status' => $value('status') ?? 'active',
        ];

        $errors = [];
        if ($data['full_name'] === null) {
            $errors['full_name'] = 'Enter the full name.';
        }
        // Office access given to a member logs in with the member's own login, so it needs neither
        if ($data['email'] === null && $data['employee_no'] === null && empty($existing['office_via_member'])) {
            $errors['email'] = 'Enter an email or an employee number - they log in with one of these.';
        }
        if ($data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'That email address doesn\'t look right.';
        }
        if ($data['joined_on'] !== null) {
            $d = \DateTime::createFromFormat('!Y-m-d', $data['joined_on']);
            if (!$d || $d->format('Y-m-d') !== $data['joined_on'] || $data['joined_on'] > date('Y-m-d')) {
                $errors['joined_on'] = 'Enter a valid date, not in the future.';
            }
        }
        if ($data['date_of_birth'] !== null) {
            $d = \DateTime::createFromFormat('!Y-m-d', $data['date_of_birth']);
            if (!$d || $d->format('Y-m-d') !== $data['date_of_birth'] || $data['date_of_birth'] > date('Y-m-d', strtotime('-16 years'))) {
                $errors['date_of_birth'] = 'Enter a valid date of birth.';
            }
        }
        if ($data['gender'] !== null && !in_array($data['gender'], ['female', 'male'], true)) {
            $errors['gender'] = 'Choose female or male.';
        }
        if (!in_array($data['role'], Roles::ALL, true)) {
            $errors['role'] = 'Choose a role.';
        }
        if (!in_array($data['status'], self::STATUSES, true)) {
            $errors['status'] = 'Choose a status.';
        }
        foreach (['email' => 'email', 'employee_no' => 'employee number', 'account_no' => 'account number'] as $field => $label) {
            if ($data[$field] === null || isset($errors[$field])) {
                continue;
            }
            $stmt = Database::tenant()->prepare("SELECT id FROM users WHERE $field = ? AND id <> ?");
            $stmt->execute([$data[$field], $existing['id'] ?? 0]);
            if ($stmt->fetchColumn()) {
                $errors[$field] = "Another user already has this $label.";
            }
        }
        // An email or employee number that is someone else's login would make login ambiguous
        foreach (['email' => 'employee_no', 'employee_no' => 'email'] as $field => $other) {
            if ($data[$field] === null || isset($errors[$field])) {
                continue;
            }
            $stmt = Database::tenant()->prepare("SELECT id FROM users WHERE $other = ? AND id <> ?");
            $stmt->execute([$data[$field], $existing['id'] ?? 0]);
            if ($stmt->fetchColumn()) {
                $errors[$field] = 'Another user logs in with this.';
            }
        }

        if ($errors) {
            Response::error(reset($errors), 422, $errors);
        }
        return $data;
    }

    /** M00042 - used when the admin doesn't give an account number */
    private static function defaultAccountNo(int $id): string
    {
        return 'M' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    /** POST /api/users/{id}/link-member { member_id|null } (super admin) - the office account's owner's member account */
    public function linkMember(Request $request): never
    {
        $office = $this->find((int) $request->param('id'));
        if (!in_array($office['role'], [Roles::ADMIN, Roles::SUPER_ADMIN], true)) {
            Response::error('Only office accounts are linked to a member account.', 422);
        }
        $memberId = (int) $request->input('member_id', 0) ?: null;
        $db = Database::tenant();
        if ($memberId) {
            $m = $db->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'member'");
            $m->execute([$memberId]);
            $member = $m->fetch() ?: Response::error('Choose a member account.', 422);
            $taken = $db->prepare('SELECT full_name FROM users WHERE linked_member_id = ? AND id <> ?');
            $taken->execute([$memberId, $office['id']]);
            if ($other = $taken->fetchColumn()) {
                Response::error("{$member['full_name']} is already linked to $other's office account.", 422);
            }
        }
        $db->prepare('UPDATE users SET linked_member_id = ? WHERE id = ?')->execute([$memberId, $office['id']]);
        Audit::log($memberId ? 'users.linked' : 'users.unlinked', 'user', (int) $office['id'], ['member' => $memberId]);
        Response::ok(['user' => AuthController::present($this->find((int) $office['id']))]);
    }
}
