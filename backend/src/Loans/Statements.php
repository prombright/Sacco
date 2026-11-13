<?php

declare(strict_types=1);

namespace Sacco\Loans;

use PDO;
use Sacco\Support\Money;
use Sacco\Support\Notify;
use Sacco\Support\Settings;

/**
 * Each member's month in a few lines: savings in and out, savings and shares at the month's end,
 * what they repaid, what is left on their loans and the next payment. Sent to every member on the
 * first daily run of a month (when the office has turned it on), and printable by the office.
 */
final class Statements
{
    /** On the first daily run of a month: last month's statement to every active member, once */
    public static function runIfDue(PDO $db): int
    {
        if (Settings::get('monthly_statements', 'off') !== 'on') {
            return 0;
        }
        $month = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        // Claims the month: only one request gets a changed row, so it is sent once
        $claim = $db->prepare("INSERT INTO settings (`key`, value) VALUES ('statements_sent_for', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        $claim->execute([$month]);
        if ($claim->rowCount() === 0) {
            return 0;
        }
        return self::send($db, $month);
    }

    /** Messages (and email copies) to every active member for the month; how many went */
    public static function send(PDO $db, string $month): int
    {
        $label = date('F Y', strtotime("$month-01"));
        $sent = 0;
        $was = Notify::$system;
        Notify::$system = true;
        try {
            foreach (self::summaries($db, $month) as $m) {
                Notify::member($m['user_id'], 'statement', "Your $label statement", self::text($m), '/statement');
                $sent++;
            }
        } finally {
            Notify::$system = $was;
        }
        return $sent;
    }

    /** The statement in words, for a message */
    public static function text(array $m): string
    {
        $c = fn (int $cents) => Notify::cents($cents);
        $parts = ['Savings ' . $c($m['savings_end']) . ($m['savings_in'] || $m['savings_out'] ? ' (in ' . $c($m['savings_in']) . ', out ' . $c($m['savings_out']) . ' this month)' : '') . '.'];
        $parts[] = 'Shares ' . $c($m['shares_end']) . '.';
        if ($m['repaid'] > 0) {
            $parts[] = 'You repaid ' . $c($m['repaid']) . '.';
        }
        if ($m['loan_left'] > 0) {
            $parts[] = 'Loan left: ' . $c($m['loan_left']) . ($m['next_due_date'] ? '; next payment ' . $c($m['next_due_amount']) . ' by ' . date('j M', strtotime($m['next_due_date'])) : '') . '.';
        }
        return implode(' ', $parts) . ' Your full statement is under My statement.';
    }

    /**
     * Every active member's month, in cents. Balances are at the month's end; loans are as they
     * stand today (a statement is read at the start of the next month).
     *
     * @return list<array{user_id: int, full_name: string, account_no: ?string, employee_no: ?string, savings_end: int, savings_in: int, savings_out: int, shares_end: int, repaid: int, loan_left: int, next_due_date: ?string, next_due_amount: int}>
     */
    public static function summaries(PDO $db, string $month): array
    {
        $from = "$month-01";
        $to = date('Y-m-t', strtotime($from));
        $money = $db->prepare(
            "SELECT u.id, u.full_name, u.account_no, u.employee_no,
                    COALESCE(SUM(CASE WHEN t.account = 'savings' AND t.value_date <= :to1 THEN t.amount END), 0) AS savings_end,
                    COALESCE(SUM(CASE WHEN t.account = 'savings' AND t.value_date BETWEEN :from1 AND :to2 AND t.amount > 0 AND t.type <> 'opening_balance' THEN t.amount END), 0) AS savings_in,
                    COALESCE(SUM(CASE WHEN t.account = 'savings' AND t.value_date BETWEEN :from2 AND :to3 AND t.amount < 0 AND t.type <> 'opening_balance' THEN -t.amount END), 0) AS savings_out,
                    COALESCE(SUM(CASE WHEN t.account = 'shares' AND t.value_date <= :to4 THEN t.amount END), 0) AS shares_end
             FROM users u LEFT JOIN transactions t ON t.user_id = u.id
             WHERE u.role = 'member' AND u.status = 'active'
             GROUP BY u.id ORDER BY u.full_name"
        );
        $money->execute(['to1' => $to, 'from1' => $from, 'to2' => $to, 'from2' => $from, 'to3' => $to, 'to4' => $to]);

        $repaid = $db->prepare('SELECT l.user_id, SUM(p.amount) FROM loan_payments p JOIN loans l ON l.id = p.loan_id WHERE p.paid_on BETWEEN ? AND ? GROUP BY l.user_id');
        $repaid->execute([$from, $to]);
        $repaidBy = $repaid->fetchAll(PDO::FETCH_KEY_PAIR);

        $loans = [];
        foreach ($db->query("SELECT id, user_id FROM loans WHERE status = 'active'") as $l) {
            $p = Loans::position($db, (int) $l['id']);
            $u = (int) $l['user_id'];
            $loans[$u] ??= ['left' => 0, 'date' => null, 'amount' => 0];
            $loans[$u]['left'] += $p['total_left'];
            if ($p['next_due_date'] && (!$loans[$u]['date'] || $p['next_due_date'] < $loans[$u]['date'])) {
                $loans[$u]['date'] = $p['next_due_date'];
                $loans[$u]['amount'] = $p['next_due_amount'];
            }
        }

        $out = [];
        foreach ($money->fetchAll() as $r) {
            $id = (int) $r['id'];
            $out[] = [
                'user_id' => $id,
                'full_name' => $r['full_name'],
                'account_no' => $r['account_no'],
                'employee_no' => $r['employee_no'],
                'savings_end' => Money::fromDecimal((string) $r['savings_end']),
                'savings_in' => Money::fromDecimal((string) $r['savings_in']),
                'savings_out' => Money::fromDecimal((string) $r['savings_out']),
                'shares_end' => Money::fromDecimal((string) $r['shares_end']),
                'repaid' => Money::fromDecimal((string) ($repaidBy[$id] ?? '0')),
                'loan_left' => $loans[$id]['left'] ?? 0,
                'next_due_date' => $loans[$id]['date'] ?? null,
                'next_due_amount' => $loans[$id]['amount'] ?? 0,
            ];
        }
        return $out;
    }
}
