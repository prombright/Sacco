<?php

declare(strict_types=1);

namespace Sacco\Support;

use PDO;
use Sacco\Loans\Loans;

/**
 * How much of a member's savings is actually theirs to move: the balance, less what they've
 * pledged as a loan guarantee, less withdrawals already asked for but not yet paid.
 */
final class Savings
{
    /** In cents. $exceptWithdrawal leaves one request out (when paying that very request). */
    public static function free(PDO $db, int $userId, ?int $exceptWithdrawal = null): int
    {
        return max(0, Ledger::balance($db, $userId, 'savings') - Loans::pledged($db, $userId) - self::heldForWithdrawals($db, $userId, $exceptWithdrawal));
    }

    public static function heldForWithdrawals(PDO $db, int $userId, ?int $except = null): int
    {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests WHERE user_id = ? AND status IN ('pending', 'approved') AND id <> ?");
        $stmt->execute([$userId, $except ?? 0]);
        return Money::fromDecimal($stmt->fetchColumn());
    }
}
