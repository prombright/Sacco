<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Auth\Roles;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Support\Money;

/**
 * A member's next of kin: who receives their savings and shares if they die. Members keep their
 * own list; admins can see and change anyone's. The shares must add up to 100%.
 */
final class MemberDetailsController
{
    /** GET /api/me/profile - their details and next of kin */
    public function myProfile(Request $request): never
    {
        $user = Auth::user();
        Response::ok(['user' => AuthController::present($user), 'next_of_kin' => $this->kin((int) $user['id'])]);
    }

    /** GET /api/members/{id}/next-of-kin (admin) */
    public function index(Request $request): never
    {
        Response::ok(['next_of_kin' => $this->kin($this->memberId($request))]);
    }

    /** PUT-style: POST /api/me/next-of-kin or /api/members/{id}/next-of-kin { people: [{ full_name, relationship, phone, share_percent }] } */
    public function save(Request $request): never
    {
        $userId = $request->param('id') !== '' ? $this->memberId($request) : (int) Auth::id();
        $people = $request->input('people', []);
        if (!is_array($people)) {
            Response::error('Send a list of people.', 422);
        }
        $clean = [];
        $total = 0;
        foreach ($people as $p) {
            $name = mb_substr(trim((string) ($p['full_name'] ?? '')), 0, 160);
            $relationship = mb_substr(trim((string) ($p['relationship'] ?? '')), 0, 40);
            $share = Money::parse($p['share_percent'] ?? '');
            if ($name === '' || $relationship === '') {
                Response::error('Each next of kin needs a name and a relationship.', 422);
            }
            if ($share === null || $share <= 0 || $share > 100_00) {
                Response::error("Give $name a share between 1% and 100%.", 422);
            }
            $total += $share;
            $clean[] = [$name, $relationship, mb_substr(trim((string) ($p['phone'] ?? '')), 0, 30) ?: null, Money::toDecimal($share)];
        }
        if ($clean && $total !== 100_00) {
            Response::error('The shares must add up to 100% (they add up to ' . rtrim(rtrim(Money::toDecimal($total), '0'), '.') . '%).', 422);
        }
        Database::transaction(function (\PDO $db) use ($userId, $clean) {
            $db->prepare('DELETE FROM next_of_kin WHERE user_id = ?')->execute([$userId]);
            $insert = $db->prepare('INSERT INTO next_of_kin (user_id, full_name, relationship, phone, share_percent) VALUES (?, ?, ?, ?, ?)');
            foreach ($clean as $row) {
                $insert->execute([$userId, ...$row]);
            }
        });
        Audit::log('next_of_kin.saved', 'user', $userId, ['count' => count($clean)]);
        Response::ok(['next_of_kin' => $this->kin($userId)]);
    }

    private function memberId(Request $request): int
    {
        if (!Roles::allows(Auth::user()['role'], [Roles::ADMIN])) {
            Response::error('You do not have permission to do that.', 403);
        }
        $stmt = Database::tenant()->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([(int) $request->param('id')]);
        return (int) ($stmt->fetchColumn() ?: Response::error('Member not found.', 404));
    }

    private function kin(int $userId): array
    {
        $stmt = Database::tenant()->prepare('SELECT id, full_name, relationship, phone, share_percent FROM next_of_kin WHERE user_id = ? ORDER BY id');
        $stmt->execute([$userId]);
        return array_map(fn ($k) => $k + ['share' => rtrim(rtrim($k['share_percent'], '0'), '.')], $stmt->fetchAll());
    }
}
