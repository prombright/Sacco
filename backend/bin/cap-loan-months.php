<?php

declare(strict_types=1);

/**
 * Re-plans running loans that are longer than the SACCO allows (the committee: 24 months) over
 * that many months from their payout date, at their own interest rate. Repayments already made
 * are applied again to the new plan. Members are not messaged; tell them their new monthly payment.
 *
 * Shows what would change and changes nothing, unless --apply is given:
 *
 *   php backend/bin/cap-loan-months.php                 (preview)
 *   php backend/bin/cap-loan-months.php --apply
 *   php backend/bin/cap-loan-months.php --max=24 --apply
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use Sacco\Database;
use Sacco\Loans\LoanMath;
use Sacco\Loans\Loans;
use Sacco\Support\Money;
use Sacco\Support\Notify;
use Sacco\Tenant;

$opt = getopt('', ['max:', 'apply']);
$max = (int) ($opt['max'] ?? 24);
$apply = isset($opt['apply']);
if ($max < 1 || $max > 120) {
    exit("--max must be between 1 and 120.\n");
}
$ugx = fn (int $cents) => number_format($cents / 100);

foreach (Database::platform()->query('SELECT * FROM saccos ORDER BY id') as $sacco) {
    Tenant::actAs($sacco, function (PDO $db) use ($max, $apply, $ugx, $sacco) {
        $loans = $db->prepare("SELECT l.*, u.full_name FROM loans l JOIN users u ON u.id = l.user_id WHERE l.status = 'active' AND l.months > ? ORDER BY l.months DESC");
        $loans->execute([$max]);
        $rows = $loans->fetchAll();
        echo "{$sacco['name']}: " . count($rows) . " running loan" . (count($rows) === 1 ? '' : 's') . " longer than $max months\n";
        if (!$rows) {
            return;
        }
        printf("  %-8s %-28s %13s %7s %13s %13s\n", 'Loan', 'Member', 'Amount', 'Months', 'Monthly now', "Over $max");
        foreach ($rows as $l) {
            $now = $db->query('SELECT principal + interest FROM loan_installments WHERE loan_id = ' . (int) $l['id'] . ' ORDER BY number LIMIT 1')->fetchColumn();
            $plan = LoanMath::schedule(Money::fromDecimal($l['amount']), $l['interest_rate_monthly'], $max, $l['disbursed_on']);
            $new = $plan[0]['principal'] + $plan[0]['interest'];
            printf("  %-8s %-28s %13s %7d %13s %13s\n", $l['loan_no'], mb_substr($l['full_name'], 0, 28), $ugx(Money::fromDecimal($l['amount'])), $l['months'], $ugx(Money::fromDecimal((string) $now)), $ugx($new));
        }
        if (!$apply) {
            echo "\n  Nothing changed. Run again with --apply to re-plan these loans over $max months.\n";
            return;
        }

        Notify::$muted = true;
        $db->beginTransaction();
        try {
            foreach ($rows as $l) {
                $before = (int) $l['months'];
                // Repayments already made are applied again, to the new plan
                Loans::replan($db, $l, $max);
                $db->prepare("INSERT INTO audit_log (actor_id, action, subject_type, subject_id, details) VALUES (NULL, 'loan.replanned', 'loan', ?, ?)")
                    ->execute([$l['id'], json_encode(['months_before' => $before, 'months' => $max])]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            Notify::$muted = false;
            exit("  Nothing was changed: " . $e->getMessage() . "\n");
        }
        Notify::$muted = false;
        echo "\n  Done: " . count($rows) . " loans re-planned over $max months. Tell the members their new monthly payment.\n";
    });
}
