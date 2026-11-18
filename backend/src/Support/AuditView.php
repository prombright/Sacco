<?php

declare(strict_types=1);

namespace Sacco\Support;

use PDO;

/**
 * The audit log made readable for the office, the committee and the auditors: each entry's area
 * (Money, Loans...), whether it needs a look (reversals, rule changes, roles...), whether it is
 * routine (switching accounts), who or what it was on by name, and its details as plain lines.
 */
final class AuditView
{
    public const AREAS = [
        'money' => 'Money',
        'loans' => 'Loans',
        'members' => 'Members',
        'rules' => 'Rules & setup',
        'books' => 'Books',
        'imports' => 'Imports',
        'security' => 'Logins & security',
    ];

    /** Changes an auditor looks at first */
    private const SENSITIVE = [
        'settings.changed', 'settings.logo', 'settings.logo_removed', 'loan_product.created', 'loan_product.updated',
        'users.office_access', 'users.linked', 'users.unlinked', 'platform.user_role', 'platform.user_status', 'user.unlocked', 'user.password_reset', 'password.reset',
        'member.slow_payer_marked', 'member.slow_payer_lifted', 'member.exited',
        'loan.months_changed', 'loan.charge_waived', 'loan.security_removed', 'loan.payment_reversed', 'loan.guarantee_on_paper', 'loan.brought_in',
        'membership_fee.waived', 'membership_fee.undone',
        'transfer.reversed', 'payroll.reversed', 'dividend.reversed', 'cashbook.reversed', 'journal.reversed', 'journal.posted',
        'investment.return_reversed', 'bank_rec.deleted', 'bank_rec.reopened',
        'year.closed', 'year.reopened', 'backup.downloaded', 'two_step.disabled', 'committee.decided', 'approval.rejected',
    ];

    /** Everyday entries hidden unless asked for: they would drown the rest */
    private const ROUTINE = ['auth.switched', 'password.changed', 'two_step.enabled', 'next_of_kin.saved', 'statements.sent'];

    public static function area(string $action): string
    {
        [$head] = explode('.', $action);
        return match (true) {
            in_array($action, ['users.imported', 'transactions.imported'], true), $head === 'old_system' => 'imports',
            in_array($head, ['transaction', 'transfer', 'payroll', 'withdrawal', 'membership_fee', 'dividend', 'profit_share', 'approval'], true) => 'money',
            in_array($head, ['loan'], true) => 'loans',
            in_array($head, ['user', 'users', 'member', 'notice', 'next_of_kin', 'statements'], true) => 'members',
            in_array($head, ['settings', 'loan_product', 'committee'], true) => 'rules',
            in_array($head, ['journal', 'account', 'cashbook', 'bank_rec', 'year', 'investment', 'budget'], true) => 'books',
            default => 'security',
        };
    }

    public static function sensitive(string $action, ?array $details): bool
    {
        if (in_array($action, self::SENSITIVE, true) || str_ends_with($action, '.reversed') || str_contains($action, 'entered_in_error')) {
            return true;
        }
        // A change of someone's role
        return $action === 'user.updated' && isset($details['role']);
    }

    public static function routine(string $action): bool
    {
        return in_array($action, self::ROUTINE, true);
    }

    /** Who or what an entry is about, by name: "Arinaitwe Bright (284)", "Loan L00064" */
    public static function subject(PDO $db, ?string $type, ?int $id): string
    {
        static $names = [];
        if (!$type || !$id) {
            return '';
        }
        $key = "$type:$id";
        if (isset($names[$key])) {
            return $names[$key];
        }
        $one = function (string $sql) use ($db, $id) {
            $s = $db->prepare($sql);
            $s->execute([$id]);
            return $s->fetchColumn();
        };
        $label = match ($type) {
            'user' => ($n = $one("SELECT CONCAT(full_name, IF(account_no IS NULL, '', CONCAT(' (', account_no, ')'))) FROM users WHERE id = ?")) ? $n : null,
            'loan' => ($n = $one('SELECT loan_no FROM loans WHERE id = ?')) ? "Loan $n" : null,
            'withdrawal' => ($n = $one('SELECT request_no FROM withdrawal_requests WHERE id = ?')) ? "Withdrawal $n" : null,
            'transaction' => ($n = $one("SELECT CONCAT(u.full_name, ': ', t.type, ' ', FORMAT(ABS(t.amount), 0)) FROM transactions t JOIN users u ON u.id = t.user_id WHERE t.id = ?")) ? str_replace('_', ' ', $n) : null,
            'transfer' => ($n = $one('SELECT transfer_no FROM transfers WHERE id = ?')) ? "Transfer $n" : null,
            'payroll_batch' => 'Upload U' . str_pad((string) $id, 5, '0', STR_PAD_LEFT),
            'dividend_run' => ($n = $one('SELECT label FROM dividend_runs WHERE id = ?')) ? "Dividend $n" : null,
            'loan_product' => ($n = $one('SELECT name FROM loan_products WHERE id = ?')) ? (string) $n : null,
            'notice' => ($n = $one('SELECT title FROM notices WHERE id = ?')) ? "Notice \"$n\"" : null,
            default => null,
        };
        return $names[$key] = $label ?? ucfirst(str_replace('_', ' ', $type)) . ' ' . $id;
    }

    /** An entry's details as plain lines: "Email: none → name@mail.com", "Total: UGX 562,684,582" */
    public static function lines(PDO $db, ?array $d): array
    {
        if (!$d) {
            return [];
        }
        $money = ['amount', 'total', 'fee', 'cheque', 'savings', 'shares', 'limit', 'value'];
        // A person's number in the details: their name instead ("Member: 29" -> "Member: Ddamulira Daniel")
        $people = ['member', 'guarantor', 'from', 'to', 'office', 'user', 'borrower'];
        $person = function ($id) use ($db): ?string {
            $s = $db->prepare('SELECT full_name FROM users WHERE id = ?');
            $s->execute([(int) $id]);
            return $s->fetchColumn() ?: null;
        };
        $show = function ($v, string $k = '') use ($money, $people, $person): string {
            if ((is_int($v) || (is_string($v) && ctype_digit($v))) && in_array($k, $people, true) && ($n = $person($v))) {
                return $n;
            }
            if ($v === null || $v === '') {
                return 'none';
            }
            if (is_bool($v)) {
                return $v ? 'yes' : 'no';
            }
            if (is_numeric($v) && in_array($k, $money, true)) {
                return 'UGX ' . number_format((float) $v);
            }
            if (is_array($v)) {
                return implode(', ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $v));
            }
            return str_replace('_', ' ', (string) $v);
        };
        $label = fn (string $k) => ucfirst(str_replace(['_no', '_id', '_'], [' number', '', ' '], $k));
        $lines = [];
        foreach ($d as $k => $v) {
            $k = (string) $k;
            if (is_array($v) && array_key_exists('from', $v) && array_key_exists('to', $v)) {
                $lines[] = $label($k) . ': ' . $show($v['from'], $k) . ' → ' . $show($v['to'], $k);
            } elseif (is_array($v) && $v && array_keys($v) !== range(0, count($v) - 1)) {
                // A group of changes: each on its own line
                foreach ($v as $k2 => $c) {
                    $k2 = (string) $k2;
                    $lines[] = is_array($c) && array_key_exists('from', $c)
                        ? $label($k2) . ': ' . $show($c['from'], $k2) . ' → ' . $show($c['to'] ?? null, $k2)
                        : $label($k2) . ': ' . $show($c, $k2);
                }
            } else {
                $lines[] = $label($k) . ': ' . $show($v, $k);
            }
        }
        return $lines;
    }

    /** "On the server itself" for the local address */
    public static function from(?string $ip): string
    {
        return in_array($ip, ['::1', '127.0.0.1'], true) ? 'On the server itself' : (string) $ip;
    }

    /** "Arinaitwe Bright (admin)" */
    public static function who(?string $name, ?string $role): string
    {
        if (!$name) {
            return 'The system';
        }
        return $name . match ($role) {
            'super_admin' => ' (super admin)',
            'admin' => ' (admin)',
            default => '',
        };
    }
}
