<?php

declare(strict_types=1);

namespace Sacco\Loans;

use PDO;
use Sacco\Support\Settings;

/**
 * A member's repayment record and standing.
 *
 * The record is facts, from their last 12 instalments that have fallen due (across all their
 * loans): how many were paid within GRACE_DAYS of the due date, how many later, the longest delay
 * and how many are unpaid now. It is shown to the office to help decide, and nothing more:
 * repayments come off salaries, so a late one is often not the member's doing.
 *
 * The standing is the office's decision: a member is a slow payer only when the super admin (or an
 * admin with the loans permission) marks them, with a reason. A marked member may borrow up to
 * slow_payer_limit_percent of their usual limit until the mark is lifted; everyone else keeps 100%.
 */
final class Credit
{
    public const GRACE_DAYS = 7;
    private const WINDOW = 12;

    public const LABELS = ['new' => 'No repayments yet', 'good' => 'Good standing', 'fair' => 'Good standing', 'poor' => 'Slow payer'];

    /**
     * @return array{grade: string, label: string, score: null, due: int, on_time: int, late: int, overdue_now: int, worst_days: int, limit_percent: int, advice: string, marked: ?array}
     */
    public static function standing(PDO $db, int $userId): array
    {
        $today = new \DateTimeImmutable('today');
        $stmt = $db->prepare(
            "SELECT i.due_date, i.cleared_on, " . Loans::LATE . " AS unpaid
             FROM loan_installments i JOIN loans l ON l.id = i.loan_id
             WHERE l.user_id = ? AND l.status IN ('active', 'closed') AND i.due_date <= ?
             ORDER BY i.due_date DESC, i.id DESC LIMIT " . self::WINDOW
        );
        $stmt->execute([$userId, $today->format('Y-m-d')]);
        $rows = $stmt->fetchAll();

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
            if ($r['unpaid']) {
                $overdue++;
            }
        }

        $mark = self::mark($db, $userId);
        $grade = $mark ? 'poor' : (!$rows ? 'new' : 'good');
        $percent = $mark ? self::slowPayerPercent() : 100;
        $advice = $mark
            ? 'The SACCO office has marked you as a slow payer' . ($mark['note'] ? " ({$mark['note']})" : '') . ", so you can borrow up to $percent% of your usual limit for now. Talk to the office about lifting it."
            : ($rows ? 'You are in good standing with the SACCO.' : 'Your record starts with your first loan repayment.');
        if ($overdue) {
            $advice = "You have $overdue instalment" . ($overdue === 1 ? '' : 's') . ' unpaid past the due date. ' . $advice;
        }

        return [
            'grade' => $grade,
            'label' => self::LABELS[$grade],
            'score' => null,
            'due' => count($rows),
            'on_time' => $onTime,
            'late' => $late,
            'overdue_now' => $overdue,
            'worst_days' => $worst,
            'limit_percent' => $percent,
            'advice' => $advice,
            'marked' => $mark,
        ];
    }

    /** The office's slow-payer mark on a member: since when, why and by whom, or null */
    public static function mark(PDO $db, int $userId): ?array
    {
        $stmt = $db->prepare('SELECT u.slow_payer_since, u.slow_payer_note, CASE WHEN b.role = \'super_admin\' AND b.office_via_member = 0 THEN \'System administrator\' ELSE b.full_name END AS by_name FROM users u LEFT JOIN users b ON b.id = u.slow_payer_by WHERE u.id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row && $row['slow_payer_since'] ? ['since' => $row['slow_payer_since'], 'note' => $row['slow_payer_note'], 'by' => $row['by_name']] : null;
    }

    /** The share of the usual limit a marked slow payer may borrow, 0 to 100 (Setup) */
    public static function slowPayerPercent(): int
    {
        return max(0, min(100, (int) Settings::get('slow_payer_limit_percent', '50')));
    }

    /** The share of their usual limit this member may borrow now */
    public static function limitPercent(PDO $db, int $userId): int
    {
        return self::mark($db, $userId) ? self::slowPayerPercent() : 100;
    }

    /** Kept for callers after a repayment: the standing no longer changes by itself */
    public static function refresh(PDO $db, int $userId): array
    {
        return self::standing($db, $userId);
    }
}
