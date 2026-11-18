<?php

declare(strict_types=1);

namespace Sacco\Support;

use PDO;
use Sacco\Loans\LoanMath;

/**
 * Interest on members' savings. The super admin sets a rate a year (or a month); a year's rate is
 * earned a twelfth each month. At the end of each month every member earns it on their savings
 * that day, rounded down to whole shillings. It is held for them, apart from their savings: it
 * doesn't count for loans or withdrawals, and the office pays it out when the committee decides.
 * What the old system owed members in savings interest is held for them too.
 *
 * Not dividends: dividends are paid on shares only.
 */
final class SavingsInterest
{
    /** The rate a month, as a percent (e.g. 0.5 for 6% a year) */
    public static function monthlyPercent(): float
    {
        $rate = (float) Settings::get('savings_interest_rate', '0');
        return Settings::get('savings_interest_per', 'year') === 'month' ? $rate : $rate / 12;
    }

    /** The rate a year, as a percent */
    public static function yearlyPercent(): float
    {
        return self::monthlyPercent() * 12;
    }

    /** What these savings earn in a month, in cents, rounded down to whole shillings */
    public static function forMonth(int $savingsCents, ?float $monthlyPercent = null): int
    {
        $p = $monthlyPercent ?? self::monthlyPercent();
        if ($p <= 0 || $savingsCents <= 0) {
            return 0;
        }
        return (int) (floor($savingsCents * $p / 100 / LoanMath::UNIT) * LoanMath::UNIT);
    }

    /** What is held for one member: brought in from the old system, and earned since (in cents) */
    public static function held(PDO $db, int $userId): int
    {
        $old = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM old_system_interest_payable WHERE user_id = ?');
        $old->execute([$userId]);
        $new = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM savings_interest WHERE user_id = ?');
        $new->execute([$userId]);
        return Money::fromDecimal((string) $old->fetchColumn()) + Money::fromDecimal((string) $new->fetchColumn());
    }

    /**
     * The daily run: every month that has ended since the rate was set earns its interest, once.
     * The first time a rate is seen, interest starts with the month running then.
     */
    public static function runIfDue(PDO $db): int
    {
        $percent = self::monthlyPercent();
        if ($percent <= 0) {
            return 0;
        }
        $lastDone = Settings::get('savings_interest_last_month', '');
        $lastOver = date('Y-m', strtotime('first day of last month'));
        if ($lastDone === '') {
            // Starts with this month: the month already over earned nothing
            Settings::set('savings_interest_last_month', $lastOver);
            return 0;
        }
        $n = 0;
        $month = date('Y-m', strtotime("$lastDone-01 +1 month"));
        while ($month <= $lastOver) {
            $n += self::earn($db, $month, $percent);
            Settings::set('savings_interest_last_month', $month);
            $month = date('Y-m', strtotime("$month-01 +1 month"));
        }
        return $n;
    }

    /** One month's interest for every member, on their savings at the month's end */
    private static function earn(PDO $db, string $month, float $percent): int
    {
        $end = date('Y-m-t', strtotime("$month-01"));
        $balances = $db->prepare(
            "SELECT u.id, COALESCE(SUM(t.amount), 0) AS savings FROM users u
             JOIN transactions t ON t.user_id = u.id AND t.account = 'savings' AND t.value_date <= ?
             WHERE u.role = 'member' GROUP BY u.id HAVING savings > 0"
        );
        $balances->execute([$end]);
        $insert = $db->prepare('INSERT IGNORE INTO savings_interest (user_id, month, balance, rate_percent_month, amount) VALUES (?, ?, ?, ?, ?)');
        $n = 0;
        foreach ($balances->fetchAll() as $b) {
            $cents = Money::fromDecimal((string) $b['savings']);
            $earned = self::forMonth($cents, $percent);
            if ($earned > 0) {
                $insert->execute([$b['id'], $month, Money::toDecimal($cents), round($percent, 5), Money::toDecimal($earned)]);
                $n += $insert->rowCount();
            }
        }
        return $n;
    }
}
