<?php

declare(strict_types=1);

/**
 * The daily run for every SACCO: reminders of loan instalments falling due and late ones, the
 * super admin's list of what falls due, and every borrower's credit standing. It also runs by
 * itself on the first look at the bell each day, so this is only needed for an exact time.
 *
 *   php backend/bin/daily.php
 *
 * From cron (cPanel -> Cron Jobs), e.g. every morning at 07:00:
 *   0 7 * * * /usr/local/bin/php /home/USER/sacco/backend/bin/daily.php >/dev/null 2>&1
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use Sacco\Database;
use Sacco\Loans\Reminders;
use Sacco\Tenant;

foreach (Database::platform()->query("SELECT * FROM saccos ORDER BY id") as $sacco) {
    $done = Tenant::actAs($sacco, function (PDO $db) {
        $db->prepare("INSERT INTO settings (`key`, value) VALUES ('daily_run_on', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([date('Y-m-d')]);
        return Reminders::run($db);
    });
    echo "SACCO {$sacco['code']}: {$done['due_soon']} due soon, {$done['overdue']} late, {$done['borrowers']} borrowers checked\n";
}
