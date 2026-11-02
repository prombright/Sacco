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

/**
 * Users = everyone who can sign in: members, admins and super admins. Admins manage members;
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

        $stmt = $db->prepare("SELECT * FROM users $sqlWhere ORDER BY full_name LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
        $stmt->execute($args);

        Response::ok([
            'users' => array_map([AuthController::class, 'present'], $stmt->fetchAll()),
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
                'INSERT INTO users (full_name, email, employee_no, account_no, phone, role, status, source, password_hash, must_change_password, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, \'manual\', ?, 1, ?)'
            )->execute([
                $data['full_name'], $data['email'], $data['employee_no'], $data['account_no'], $data['phone'],
                $data['role'], $data['status'], Passwords::hash($temporary), Auth::id(),
            ]);
            $id = (int) $db->lastInsertId();
            if ($data['account_no'] === null) {
                $db->prepare('UPDATE users SET account_no = ? WHERE id = ?')->execute([self::defaultAccountNo($id), $id]);
            }
            return $id;
        });
        Audit::log('user.created', 'user', $id, ['role' => $data['role']]);

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

        Database::tenant()->prepare(
            'UPDATE users SET full_name = ?, email = ?, employee_no = ?, account_no = ?, phone = ?, role = ?, status = ? WHERE id = ?'
        )->execute([
            $data['full_name'], $data['email'], $data['employee_no'], $data['account_no'] ?? $user['account_no'],
            $data['phone'], $data['role'], $data['status'], $user['id'],
        ]);

        $changed = [];
        foreach (['full_name', 'email', 'employee_no', 'account_no', 'phone', 'role', 'status'] as $field) {
            if (($data[$field] ?? $user[$field]) !== $user[$field]) {
                $changed[$field] = ['from' => $user[$field], 'to' => $data[$field]];
            }
        }
        if ($changed) {
            Audit::log('user.updated', 'user', (int) $user['id'], $changed);
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
            'UPDATE users SET password_hash = ?, must_change_password = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?'
        )->execute([Passwords::hash($temporary), $user['id']]);
        Audit::log('user.password_reset', 'user', (int) $user['id']);

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
            'phone' => $value('phone'),
            'role' => $value('role') ?? Roles::MEMBER,
            'status' => $value('status') ?? 'active',
        ];

        $errors = [];
        if ($data['full_name'] === null) {
            $errors['full_name'] = 'Enter the full name.';
        }
        if ($data['email'] === null && $data['employee_no'] === null) {
            $errors['email'] = 'Enter an email or an employee number - they sign in with one of these.';
        }
        if ($data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'That email address doesn\'t look right.';
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
        // An email or employee number that is someone else's login would make sign-in ambiguous
        foreach (['email' => 'employee_no', 'employee_no' => 'email'] as $field => $other) {
            if ($data[$field] === null || isset($errors[$field])) {
                continue;
            }
            $stmt = Database::tenant()->prepare("SELECT id FROM users WHERE $other = ? AND id <> ?");
            $stmt->execute([$data[$field], $existing['id'] ?? 0]);
            if ($stmt->fetchColumn()) {
                $errors[$field] = 'Another user signs in with this.';
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
}
