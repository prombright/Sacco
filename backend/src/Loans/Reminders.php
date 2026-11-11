<?php

declare(strict_types=1);

namespace Sacco\Loans;

use PDO;
use Sacco\Database;
use Sacco\Support\Notify;
use Sacco\Support\Settings;

/**
 * The daily run: reminds members of instalments falling due and late ones, gives the super admin
 * the list of what falls due, and works out every borrower's credit standing again.
 *
 * It runs once a day per SACCO, on the first look at the bell that day (no cron job needed), or
 * from cron with bin/daily.php.
 */
final class Reminders
{
    /** Runs today's reminders unless they've already run today */
    public static function runIfDue(): void
    {
        $db = Database::tenant();
        $today = date('Y-m-d');
        // Claims today's run: only one request gets a changed row, so it runs once
        $claim = $db->prepare("INSERT INTO settings (`key`, value) VALUES ('daily_run_on', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        $claim->execute([$today]);
        if ($claim->rowCount() === 0) {
            return;
        }
        self::run($db);
    }

    /** @return array{due_soon: int, overdue: int, borrowers: int} */
    public static function run(PDO $db): array
    {
        // Paused, e.g. right after old loans are brought in, until the repayments since are entered
        if (Settings::get('loan_reminders', 'on') === 'off') {
            return ['due_soon' => 0, 'overdue' => 0, 'borrowers' => 0];
        }
        Notify::$system = true;
        try {
            $days = max(1, min(14, (int) Settings::get('loan_reminder_days', '3')));
            $today = date('Y-m-d');
            $soon = self::installments($db, "i.due_date BETWEEN ? AND ?", [$today, date('Y-m-d', strtotime("+$days days"))], 'due_soon');
            // Late ones: from the day after the due date, for instalments due in the last month only
            $late = self::installments($db, 'i.due_date < ? AND i.due_date >= ?', [$today, date('Y-m-d', strtotime('-30 days'))], 'overdue');
            $mark = $db->prepare('INSERT IGNORE INTO loan_reminders (installment_id, kind, sent_on) VALUES (?, ?, ?)');

            foreach ($soon as $i) {
                $when = $i['due_date'] === $today ? 'today' : 'on ' . date('l j F', strtotime($i['due_date']));
                Notify::member((int) $i['user_id'], 'loan_due',
                    'Your loan instalment is due ' . ($i['due_date'] === $today ? 'today' : date('j M', strtotime($i['due_date']))),
                    Notify::money($i['left']) . " on loan {$i['loan_no']} is due $when. Paying on time keeps your credit standing good, so you can keep borrowing up to your full limit.",
                    "/loans/{$i['loan_id']}");
                $mark->execute([$i['id'], 'due_soon', $today]);
            }
            foreach ($late as $i) {
                Notify::member((int) $i['user_id'], 'loan_late',
                    'Your loan instalment is late',
                    Notify::money($i['left']) . " on loan {$i['loan_no']} was due on " . date('j F', strtotime($i['due_date']))
                        . '. Please pay it within ' . Credit::GRACE_DAYS . ' days of the due date to keep your credit standing; later repayments lower it.',
                    "/loans/{$i['loan_id']}");
                $mark->execute([$i['id'], 'overdue', $today]);
            }

            if ($soon || $late) {
                $line = fn (array $i) => "{$i['full_name']}: " . Notify::money($i['left']) . ' (' . date('j M', strtotime($i['due_date'])) . ", {$i['loan_no']})";
                $parts = [];
                if ($soon) {
                    $parts[] = 'Due in the next ' . $days . ' days: ' . implode('; ', array_map($line, array_slice($soon, 0, 8))) . (count($soon) > 8 ? '; and ' . (count($soon) - 8) . ' more' : '') . '.';
                }
                if ($late) {
                    $parts[] = 'Became late: ' . implode('; ', array_map($line, array_slice($late, 0, 8))) . (count($late) > 8 ? '; and ' . (count($late) - 8) . ' more' : '') . '.';
                }
                $title = $soon
                    ? count($soon) . ' loan instalment' . (count($soon) === 1 ? '' : 's') . " due in the next $days days"
                    : count($late) . ' loan instalment' . (count($late) === 1 ? ' is' : 's are') . ' late';
                Notify::superAdmins('loan_due', $title, implode(' ', $parts) . ' Each member has been reminded.', '/reports/loan-portfolio');
            }

            $borrowers = $db->query("SELECT DISTINCT user_id FROM loans WHERE status IN ('active', 'closed')")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($borrowers as $id) {
                Credit::refresh($db, (int) $id);
            }
            \Sacco\Support\Outbox::flush($db, 50);
            return ['due_soon' => count($soon), 'overdue' => count($late), 'borrowers' => count($borrowers)];
        } finally {
            Notify::$system = false;
        }
    }

    /** Unpaid instalments on active loans matching $where that haven't had this reminder yet */
    private static function installments(PDO $db, string $where, array $args, string $kind): array
    {
        $stmt = $db->prepare(
            "SELECT i.id, i.due_date, (i.principal + i.interest - i.principal_paid - i.interest_paid) AS `left`,
                    l.id AS loan_id, l.loan_no, l.user_id, u.full_name
             FROM loan_installments i JOIN loans l ON l.id = i.loan_id JOIN users u ON u.id = l.user_id
             WHERE l.status = 'active' AND (i.principal + i.interest) > (i.principal_paid + i.interest_paid) AND $where
               AND NOT EXISTS (SELECT 1 FROM loan_reminders r WHERE r.installment_id = i.id AND r.kind = ?)
             ORDER BY i.due_date, u.full_name"
        );
        $stmt->execute([...$args, $kind]);
        return $stmt->fetchAll();
    }
}
