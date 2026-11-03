<?php

declare(strict_types=1);

namespace Sacco\Support;

use PDO;
use Sacco\Loans\LoanMath;
use Sacco\Loans\Loans;

/**
 * How much of a member's savings is actually theirs to move, and the SACCO's rules around it:
 * the minimum balance that must stay in savings, the withdrawal fee, and the minimum shares.
 */
final class Savings
{
    /**
     * Savings less what is pledged as a loan guarantee and what is already asked for in a
     * withdrawal not yet paid. In cents. $exceptWithdrawal leaves one request out (when paying it).
     */
    public static function free(PDO $db, int $userId, ?int $exceptWithdrawal = null): int
    {
        return max(0, Ledger::balance($db, $userId, 'savings') - Loans::pledged($db, $userId) - self::heldForWithdrawals($db, $userId, $exceptWithdrawal));
    }

    /** What can actually leave savings: free savings less the minimum that must always stay */
    public static function withdrawable(PDO $db, int $userId, ?int $exceptWithdrawal = null): int
    {
        return max(0, self::free($db, $userId, $exceptWithdrawal) - self::minBalance());
    }

    /** Requests waiting or approved hold their amount (and the fee, when it's charged on top) */
    public static function heldForWithdrawals(PDO $db, int $userId, ?int $except = null): int
    {
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(amount + IF(fee_on_top, fee, 0)), 0) FROM withdrawal_requests
             WHERE user_id = ? AND status IN ('pending', 'approved') AND id <> ?"
        );
        $stmt->execute([$userId, $except ?? 0]);
        return Money::fromDecimal($stmt->fetchColumn());
    }

    /** At least this much stays in savings, in cents */
    public static function minBalance(): int
    {
        return max(0, (int) Money::parse(Settings::get('savings_min_balance', '0')));
    }

    /** The withdrawal fee on an amount, rounded to whole currency units, in cents */
    public static function withdrawalFee(int $amount): int
    {
        $hundredths = (int) Money::parse(Settings::get('withdrawal_fee_percent', '0'));
        return (int) (round($amount * ($hundredths / 10000) / LoanMath::UNIT) * LoanMath::UNIT);
    }

    /** True when the fee is charged on top of the amount; false when it comes out of it */
    public static function feeOnTop(): bool
    {
        return Settings::get('withdrawal_fee_mode', 'from_amount') === 'on_top';
    }

    /** The share capital every member must hold (min shares x share price), in cents; 0 = no rule */
    public static function minShareCapital(): int
    {
        $price = (int) Money::parse(Settings::get('share_price', '0'));
        return max(0, (int) Settings::get('min_shares', '0')) * max(0, $price);
    }

    /** Shares, the minimum and what's still needed, for showing to the member and the office */
    public static function sharePosition(PDO $db, int $userId): array
    {
        $capital = Ledger::balance($db, $userId, 'shares');
        $price = (int) Money::parse(Settings::get('share_price', '0'));
        $min = self::minShareCapital();
        return [
            'share_price' => Money::toDecimal($price),
            'shares' => $price > 0 ? intdiv($capital, $price) : null,
            'min_shares' => (int) Settings::get('min_shares', '0'),
            'min_capital' => Money::toDecimal($min),
            'still_needed' => Money::toDecimal(max(0, $min - $capital)),
        ];
    }
}
