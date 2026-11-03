<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Auth\Passwords;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Support\Ledger;
use Sacco\Support\Money;

/**
 * Bringing in many members at once from Excel, usually when the SACCO starts on this system:
 * each row becomes a member with a temporary password, and their savings and share balances
 * from the old records become opening balances. Checked first; saved all together or not at all.
 *
 * Rows: { row, full_name, email, employee_no, account_no, phone, joined_on, savings, shares }
 */
final class MemberImportController
{
    private const MAX_ROWS = 2000;

    /** POST /api/members/import/preview { rows } */
    public function preview(Request $request): never
    {
        Response::ok($this->report($this->check($this->rows($request))));
    }

    /** POST /api/members/import { rows } - returns each new member's login and temporary password, once */
    public function import(Request $request): never
    {
        $input = $this->rows($request);
        $created = Database::transaction(function (\PDO $db) use ($input) {
            $rows = $this->check($input);
            $report = $this->report($rows);
            if ($report['error_count'] > 0) {
                Response::error('Some rows have problems. Fix them in the file and upload it again.', 422, ['report' => $report]);
            }
            $insert = $db->prepare(
                "INSERT INTO users (full_name, email, employee_no, account_no, joined_on, phone, role, status, source, password_hash, must_change_password, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'member', 'active', 'manual', ?, 1, ?)"
            );
            $created = [];
            foreach ($rows as $r) {
                $temporary = Passwords::temporary();
                $insert->execute([$r['full_name'], $r['email'], $r['employee_no'], $r['account_no'], $r['joined_on'], $r['phone'], Passwords::hash($temporary), Auth::id()]);
                $id = (int) $db->lastInsertId();
                if ($r['account_no'] === null) {
                    $r['account_no'] = 'M' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
                    $db->prepare('UPDATE users SET account_no = ? WHERE id = ?')->execute([$r['account_no'], $id]);
                }
                $meta = ['value_date' => $r['joined_on'] ?? date('Y-m-d'), 'reference' => 'Opening balance', 'narration' => 'Brought in from the old records'];
                foreach (['savings', 'shares'] as $account) {
                    if ($r[$account] > 0) {
                        Ledger::post($db, $id, $account, 'opening_balance', $r[$account], $meta);
                    }
                }
                $created[] = [
                    'full_name' => $r['full_name'],
                    'account_no' => $r['account_no'],
                    'login' => $r['email'] ?? $r['employee_no'],
                    'temporary_password' => $temporary,
                ];
            }
            return $created;
        });
        Audit::log('users.imported', null, null, ['count' => count($created)]);

        Response::ok(['created' => $created], 201);
    }

    private function rows(Request $request): array
    {
        $rows = $request->input('rows', []);
        if (!is_array($rows) || !$rows) {
            Response::error('The file has no rows.', 422);
        }
        if (count($rows) > self::MAX_ROWS) {
            Response::error('A file can have at most ' . self::MAX_ROWS . ' rows.', 422);
        }
        return array_values($rows);
    }

    private function check(array $input): array
    {
        $db = Database::tenant();
        $taken = ['email' => [], 'employee_no' => [], 'account_no' => []];
        foreach ($db->query('SELECT email, employee_no, account_no FROM users') as $u) {
            foreach ($taken as $k => $_) {
                if ($u[$k] !== null) {
                    $taken[$k][strtolower($u[$k])] = 'an existing member';
                }
            }
        }
        // Someone's email can't be someone else's employee number: either could be typed to sign in
        $logins = $taken['email'] + $taken['employee_no'];

        $out = [];
        $inFile = ['email' => [], 'employee_no' => [], 'account_no' => []];
        foreach ($input as $i => $raw) {
            $raw = is_array($raw) ? $raw : [];
            $text = fn (string $k) => ($v = trim((string) ($raw[$k] ?? ''))) === '' ? null : $v;
            $r = [
                'row' => (int) ($raw['row'] ?? $i + 2),
                'full_name' => $text('full_name'),
                'email' => ($e = $text('email')) ? strtolower($e) : null,
                'employee_no' => $text('employee_no'),
                'account_no' => $text('account_no'),
                'phone' => $text('phone'),
                'joined_on' => null,
                'savings' => 0,
                'shares' => 0,
                'errors' => [],
            ];

            if (!$r['full_name']) {
                $r['errors'][] = 'No name.';
            }
            if (!$r['email'] && !$r['employee_no']) {
                $r['errors'][] = 'Needs an email or an employee number to sign in with.';
            }
            if ($r['email'] && !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
                $r['errors'][] = "\"{$r['email']}\" isn't a valid email.";
            }
            foreach (['email' => 'email', 'employee_no' => 'employee number', 'account_no' => 'account number'] as $k => $label) {
                if (!$r[$k]) {
                    continue;
                }
                $key = strtolower($r[$k]);
                if (isset($taken[$k][$key]) || ($k !== 'account_no' && isset($logins[$key]))) {
                    $r['errors'][] = "That $label already belongs to an existing member.";
                } elseif (isset($inFile[$k][$key])) {
                    $r['errors'][] = "That $label is also on row {$inFile[$k][$key]}.";
                } else {
                    $inFile[$k][$key] = $r['row'];
                }
            }

            $joined = $text('joined_on');
            if ($joined) {
                $date = self::date($joined);
                if (!$date) {
                    $r['errors'][] = "\"$joined\" isn't a date we can read (use e.g. 2019-02-01).";
                } elseif ($date > date('Y-m-d')) {
                    $r['errors'][] = 'Member since is in the future.';
                } else {
                    $r['joined_on'] = $date;
                }
            }
            foreach (['savings', 'shares'] as $k) {
                $v = $text($k);
                if ($v === null) {
                    continue;
                }
                $cents = Money::parse($v);
                if ($cents === null || $cents < 0) {
                    $r['errors'][] = ucfirst($k) . " \"$v\" isn't a valid amount.";
                } else {
                    $r[$k] = $cents;
                }
            }
            $out[] = $r;
        }
        return $out;
    }

    /** Dates as Excel or people write them: 2019-02-01, 01/02/2019 (day first), 1 Feb 2019 */
    private static function date(string $s): ?string
    {
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!j/n/Y', '!d M Y', '!j M Y', '!d F Y', '!j F Y'] as $format) {
            $d = \DateTime::createFromFormat($format, $s);
            if ($d && !\DateTime::getLastErrors()) {
                return $d->format('Y-m-d');
            }
        }
        return null;
    }

    private function report(array $rows): array
    {
        $ok = array_filter($rows, fn ($r) => !$r['errors']);
        return [
            'rows' => array_map(fn ($r) => array_merge($r, ['savings' => Money::toDecimal($r['savings']), 'shares' => Money::toDecimal($r['shares'])]), $rows),
            'row_count' => count($rows),
            'error_count' => count($rows) - count($ok),
            'totals' => [
                'savings' => Money::toDecimal(array_sum(array_column($ok, 'savings'))),
                'shares' => Money::toDecimal(array_sum(array_column($ok, 'shares'))),
            ],
        ];
    }
}
