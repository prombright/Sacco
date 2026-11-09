<?php

declare(strict_types=1);

namespace Sacco\Loans;

use PDO;
use Sacco\Support\Notify;
use Sacco\Support\Settings;

/**
 * How well a member repays, from their last 12 instalments that have fallen due (across all their
 * loans). Each starts the member at 100 and a late one takes points off; paying on time pushes the
 * old late ones out of the 12, so a member climbs back by paying on time.
 *
 *  - paid within GRACE_DAYS of the due date: on time
 *  - 8 to 30 days late: -10, 31 to 90: -20, more than 90: -35
 *  - still unpaid after the grace days: a further -10
 *
 * 80 and up is good, 50 to 79 fair, below 50 poor (a slow payer). A slow payer can borrow only
 * slow_payer_limit_percent of their usual limit, and the super admin is told when someone becomes one.
 */
final class Credit
{
    public const GRACE_DAYS = 7;
    private const WINDOW = 12;

    public const LABELS = ['new' => 'No repayments yet', 'good' => 'Good payer', 'fair' => 'Fair payer', 'poor' => 'Slow payer'];

    /**
     * @return array{grade: string, label: string, score: ?int, due: int, on_time: int, late: int, overdue_now: int, worst_days: int, limit_percent: int, advice: string}
     */
    public static function standing(PDO $db, int $userId): array
    {
        $today = new \DateTimeImmutable('today');
        $stmt = $db->prepare(
            "SELECT i.due_date, i.cleared_on, (i.principal + i.interest) > (i.principal_paid + i.interest_paid) AS unpaid
             FROM loan_installments i JOIN loans l ON l.id = i.loan_id
             WHERE l.user_id = ? AND l.status IN ('active', 'closed') AND i.due_date <= ?
             ORDER BY i.due_date DESC, i.id DESC LIMIT " . self::WINDOW
        );
        $stmt->execute([$userId, $today->format('Y-m-d')]);
        $rows = $stmt->fetchAll();

        $score = 100;
        $onTime = $late = $overdue = $worst = 0;
        foreach ($rows as $r) {
            $due = new \DateTimeImmutable($r['due_date']);
            $paid = $r['unpaid'] ? $today : ($r['cleared_on'] ? new \DateTimeImmutable($r['cleared_on']) : $due);
            $days = $paid > $due ? (int) $due->diff($paid)->days : 0;
            $worst = max($worst, $days);
            if ($days <= self::GRACE_DAYS) {
                $onTime++;
                continue;
            }
            $late++;
            $score -= $days > 90 ? 35 : ($days > 30 ? 20 : 10);
            if ($r['unpaid']) {
                $overdue++;
                $score -= 10;
            }
        }
        $score = max(0, $score);
        $grade = !$rows ? 'new' : ($score >= 80 ? 'good' : ($score >= 50 ? 'fair' : 'poor'));
        $percent = $grade === 'poor' ? self::slowPayerPercent() : 100;

        $advice = match ($grade) {
            'new' => 'Your standing starts with your first loan repayment.',
            'good' => 'You repay on time. Keep it up to keep your full borrowing limit.',
            'fair' => 'Some repayments came in late. Paying the next ones on time raises your standing.',
            'poor' => "Several repayments came in late, so you can borrow up to $percent% of your usual limit for now. Each instalment paid on time raises your standing.",
        };
        if ($overdue) {
            $advice = "You have $overdue instalment" . ($overdue === 1 ? '' : 's') . ' overdue. Paying ' . ($overdue === 1 ? 'it' : 'them') . ' now stops your standing from dropping further. ' . $advice;
        }

        return [
            'grade' => $grade,
            'label' => self::LABELS[$grade],
            'score' => $rows ? $score : null,
            'due' => count($rows),
            'on_time' => $onTime,
            'late' => $late,
            'overdue_now' => $overdue,
            'worst_days' => $worst,
            'limit_percent' => $percent,
            'advice' => $advice,
        ];
    }

    /** The share of the usual limit a slow payer may borrow, 0 to 100 */
    public static function slowPayerPercent(): int
    {
        return max(0, min(100, (int) Settings::get('slow_payer_limit_percent', '50')));
    }

    /** The share of their usual limit this member may borrow now */
    public static function limitPercent(PDO $db, int $userId): int
    {
        return self::standing($db, $userId)['limit_percent'];
    }

    /**
     * Works out the member's standing again and, when it crosses into or out of "slow payer", tells
     * the super admin and the member. Returns the standing.
     */
    public static function refresh(PDO $db, int $userId): array
    {
        $now = self::standing($db, $userId);
        $stmt = $db->prepare('SELECT full_name, account_no, credit_grade FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || $user['credit_grade'] === $now['grade']) {
            return $now;
        }
        $db->prepare('UPDATE users SET credit_grade = ? WHERE id = ?')->execute([$now['grade'], $userId]);

        $who = $user['full_name'] . ($user['account_no'] ? " ({$user['account_no']})" : '');
        $link = "/members/$userId";
        if ($now['grade'] === 'poor') {
            Notify::superAdmins(
                'slow_payer',
                "$who is now a slow payer",
                "{$now['late']} of their last {$now['due']} instalments came in late" . ($now['overdue_now'] ? ", {$now['overdue_now']} still unpaid" : '')
                    . ", the longest {$now['worst_days']} days. They can now borrow up to {$now['limit_percent']}% of their usual limit until they catch up.",
                $link,
            );
            Notify::member($userId, 'slow_payer', 'Your loan repayments are running late',
                $now['advice'], '/loans');
        } elseif ($user['credit_grade'] === 'poor') {
            Notify::superAdmins('slow_payer', "$who is repaying well again", "Their standing is back to \"{$now['label']}\", so their full borrowing limit is back.", $link);
            Notify::member($userId, 'credit_up', 'Your full borrowing limit is back',
                'Thank you for catching up on your repayments. You can borrow up to your full limit again.', '/loans');
        }
        return $now;
    }
}
