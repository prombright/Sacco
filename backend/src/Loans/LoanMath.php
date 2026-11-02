<?php

declare(strict_types=1);

namespace Sacco\Loans;

use Sacco\Support\Money;

/**
 * Repayment schedules: equal monthly instalments, interest each month on what is still owed
 * (reducing balance). All amounts in cents; instalments are rounded up to whole currency units
 * (shillings) and the last instalment takes whatever is left, so the principal always adds up.
 */
final class LoanMath
{
    /** Whole shillings */
    public const UNIT = 100;

    /**
     * @param string $monthlyRate percent per month, e.g. "1.0"
     * @return list<array{number: int, due_date: string, principal: int, interest: int, total: int, balance_after: int}>
     */
    public static function schedule(int $principal, string $monthlyRate, int $months, string $startDate): array
    {
        $basisPoints = (int) Money::parse($monthlyRate); // "1.0" -> 100 (1% = 100 basis points)
        $installment = self::installment($principal, $basisPoints, $months);

        $rows = [];
        $owed = $principal;
        for ($n = 1; $n <= $months; $n++) {
            $interest = self::toUnit(intdiv($owed * $basisPoints + 5000, 10000), false);
            $principalPart = $n === $months ? $owed : min($owed, $installment - $interest);
            $owed -= $principalPart;
            $rows[] = [
                'number' => $n,
                'due_date' => self::addMonths($startDate, $n),
                'principal' => $principalPart,
                'interest' => $interest,
                'total' => $principalPart + $interest,
                'balance_after' => $owed,
            ];
        }
        return $rows;
    }

    /** The equal monthly payment, rounded up to a whole unit */
    public static function installment(int $principal, int $basisPoints, int $months): int
    {
        if ($basisPoints === 0) {
            return self::toUnit(intdiv($principal + $months - 1, $months));
        }
        $r = $basisPoints / 10000;
        return self::toUnit((int) ceil($principal * $r / (1 - (1 + $r) ** -$months)));
    }

    /** Same day $months later; the 31st becomes the month's last day when it's shorter */
    public static function addMonths(string $date, int $months): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        $total = $y * 12 + ($m - 1) + $months;
        $year = intdiv($total, 12);
        $month = $total % 12 + 1;
        $day = min($d, (int) date('t', mktime(0, 0, 0, $month, 1, $year)));
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** Up to the next whole unit (or to the nearest, for interest) */
    private static function toUnit(int $cents, bool $up = true): int
    {
        return $up
            ? (int) (ceil($cents / self::UNIT) * self::UNIT)
            : (int) (round($cents / self::UNIT) * self::UNIT);
    }
}
