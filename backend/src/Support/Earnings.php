<?php

declare(strict_types=1);

namespace Sacco\Support;

use PDO;
use Sacco\Loans\LoanMath;

/**
 * What members earn each year:
 *
 * - Dividend: dividend_rate_percent (40%) of each member's share capital.
 * - Profit share: the profit the SACCO's investments bring in a year, shared among members in
 *   proportion to their savings - by default their average month-end savings over the year, so
 *   saving all year counts and a deposit in December doesn't take the year's profit.
 *
 * Expected figures (what investments should earn, from their expected return) drive the
 * estimates members see during the year; the actual profit recorded drives the payout.
 */
final class Earnings
{
    public const INVESTMENT_KINDS = [
        'mutual_fund' => 'Unit trust / mutual fund',
        'fixed_deposit' => 'Fixed deposit',
        'treasury' => 'Treasury bill / bond',
        'shares' => 'Company shares',
        'property' => 'Land / property',
        'business' => 'Business',
        'other' => 'Other',
    ];

    public static function dividendRate(): string
    {
        return Settings::get('dividend_rate_percent', '0');
    }

    /** Expected profit of one investment within $year (pro rata to the days it was held), in cents */
    public static function expectedFor(array $inv, int $year, ?string $until = null): int
    {
        $yearStart = "$year-01-01";
        $yearEnd = $until ?? "$year-12-31";
        $from = max($yearStart, $inv['started_on']);
        $to = min($yearEnd, $inv['closed_on'] ?? $yearEnd);
        if ($to < $from) {
            return 0;
        }
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
        $daysInYear = (int) date('z', mktime(0, 0, 0, 12, 31, $year)) + 1;
        // Stored as "12.000"; trimmed to "12" before reading, as amounts allow two decimals
        $rate = (int) Money::parse(rtrim(rtrim((string) $inv['expected_return_percent'], '0'), '.') ?: '0') / 10000; // "12" -> 0.12
        // Whole shillings
        return (int) (round(Money::fromDecimal($inv['amount']) * $rate * $days / $daysInYear / LoanMath::UNIT) * LoanMath::UNIT);
    }

    /** Everything about investments for a year, in cents */
    public static function investmentYear(PDO $db, int $year): array
    {
        $investments = $db->query('SELECT * FROM investments ORDER BY started_on, id')->fetchAll();
        $expected = 0;
        $expectedSoFar = 0;
        $invested = 0;
        $today = date('Y-m-d');
        foreach ($investments as $inv) {
            $expected += self::expectedFor($inv, $year);
            $expectedSoFar += self::expectedFor($inv, $year, min($today, "$year-12-31"));
            if ($inv['status'] === 'active') {
                $invested += Money::fromDecimal($inv['amount']);
            }
        }
        $stmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM investment_returns WHERE received_on BETWEEN ? AND ?');
        $stmt->execute(["$year-01-01", "$year-12-31"]);
        return [
            'invested' => $invested,
            'expected_profit' => $expected,
            'expected_so_far' => $expectedSoFar,
            'actual_profit' => Money::fromDecimal($stmt->fetchColumn()),
        ];
    }

    /**
     * Each member's weight in the profit share for $year, in cents: their average month-end
     * savings (Jan to the last month-end so far), or their savings at the year's end.
     *
     * @return array<int, int> user id => basis
     */
    public static function profitBasis(PDO $db, int $year, ?string $mode = null): array
    {
        $mode ??= Settings::get('profit_basis', 'average_savings');
        $lastMonth = $year < (int) date('Y') ? 12 : max(1, (int) date('n'));
        $monthEnds = [];
        for ($m = 1; $m <= $lastMonth; $m++) {
            $monthEnds[] = date('Y-m-t', mktime(0, 0, 0, $m, 1, $year));
        }
        if ($mode === 'year_end_savings') {
            $monthEnds = [end($monthEnds)];
        }

        // Savings before the year, then each month's movements; the running total gives month-end balances
        $members = $db->query("SELECT id FROM users WHERE role = 'member'")->fetchAll(PDO::FETCH_COLUMN);
        $before = $db->prepare("SELECT user_id, SUM(amount) FROM transactions WHERE account = 'savings' AND value_date < ? GROUP BY user_id");
        $before->execute(["$year-01-01"]);
        $opening = $before->fetchAll(PDO::FETCH_KEY_PAIR);
        $moves = $db->prepare(
            "SELECT user_id, DATE_FORMAT(value_date, '%m') AS m, SUM(amount) AS total FROM transactions
             WHERE account = 'savings' AND value_date BETWEEN ? AND ? GROUP BY user_id, m"
        );
        $moves->execute(["$year-01-01", "$year-12-31"]);
        $byMonth = [];
        foreach ($moves->fetchAll() as $r) {
            $byMonth[(int) $r['user_id']][(int) $r['m']] = Money::fromDecimal($r['total']);
        }

        $basis = [];
        foreach ($members as $id) {
            $id = (int) $id;
            $balance = Money::fromDecimal($opening[$id] ?? null);
            $sum = 0;
            $count = 0;
            for ($m = 1; $m <= $lastMonth; $m++) {
                $balance += $byMonth[$id][$m] ?? 0;
                if (in_array(date('Y-m-t', mktime(0, 0, 0, $m, 1, $year)), $monthEnds, true)) {
                    $sum += max(0, $balance);
                    $count++;
                }
            }
            // Whole shillings
            $avg = $count ? intdiv(intdiv($sum, $count), LoanMath::UNIT) * LoanMath::UNIT : 0;
            if ($avg > 0) {
                $basis[$id] = $avg;
            }
        }
        return $basis;
    }

    /**
     * Shares a pool among members by their basis, each rounded down to whole shillings.
     *
     * @return array<int, int> user id => cents
     */
    public static function split(int $pool, array $basis): array
    {
        $total = array_sum($basis);
        if ($pool <= 0 || $total <= 0) {
            return [];
        }
        $out = [];
        foreach ($basis as $id => $b) {
            $out[$id] = (int) (floor($pool * ($b / $total) / LoanMath::UNIT) * LoanMath::UNIT);
        }
        return $out;
    }

    /** What a member can expect this year, and what they got last year - for their dashboard */
    public static function forMember(PDO $db, int $userId): array
    {
        $year = (int) date('Y');
        $shares = Ledger::balance($db, $userId, 'shares');
        $rate = self::dividendRate();
        $dividend = (int) (floor($shares * ((int) Money::parse($rate) / 10000) / LoanMath::UNIT) * LoanMath::UNIT);

        $inv = self::investmentYear($db, $year);
        $basis = self::profitBasis($db, $year);
        $mine = $basis[$userId] ?? 0;
        $total = array_sum($basis);
        // The bigger of what investments should earn and what they have already brought
        $pool = max($inv['expected_profit'], $inv['actual_profit']);
        $profit = $total > 0 ? (int) (floor($pool * ($mine / $total) / LoanMath::UNIT) * LoanMath::UNIT) : 0;

        $last = $db->prepare(
            "SELECT r.kind, r.label, SUM(t.amount) AS amount FROM transactions t JOIN dividend_runs r ON r.id = t.dividend_run_id
             WHERE t.user_id = ? AND t.type IN ('dividend', 'profit') AND r.status = 'posted' AND r.year = ? GROUP BY r.id"
        );
        $last->execute([$userId, $year - 1]);

        return [
            'year' => $year,
            'dividend' => [
                'rate_percent' => $rate,
                'shares' => Money::toDecimal($shares),
                'estimate' => Money::toDecimal($dividend),
            ],
            'profit' => [
                'basis' => Settings::get('profit_basis', 'average_savings'),
                'your_savings_basis' => Money::toDecimal($mine),
                'share_percent' => $total > 0 ? round($mine / $total * 100, 2) : 0,
                'expected_pool' => Money::toDecimal($pool),
                'received_so_far' => Money::toDecimal($inv['actual_profit']),
                'estimate' => Money::toDecimal($profit),
                'credit_account' => Settings::get('profit_credit_account', 'savings'),
            ],
            'total_estimate' => Money::toDecimal($dividend + $profit),
            'last_year' => $last->fetchAll(),
        ];
    }
}
