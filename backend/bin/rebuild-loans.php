<?php

declare(strict_types=1);

/**
 * Works out again, for every disbursed loan in every SACCO, what each instalment and charge has
 * had paid and how each repayment was split. Safe to run any time; needed once after migration
 * 007 so loans repaid before it are counted in the income statement.
 *
 *   php backend/bin/rebuild-loans.php
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use Sacco\Database;
use Sacco\Loans\Loans;
use Sacco\Support\Notify;
use Sacco\Tenant;

foreach (Database::platform()->query('SELECT * FROM saccos ORDER BY id') as $sacco) {
    // As that SACCO, so messages (a guarantor released, a slow payer) reach its members
    $count = Tenant::actAs($sacco, function (PDO $db) {
        $ids = $db->query("SELECT id FROM loans WHERE status IN ('active', 'closed')")->fetchAll(PDO::FETCH_COLUMN);
        Notify::$system = true;
        $db->beginTransaction();
        foreach ($ids as $id) {
            Loans::applyPayments($db, (int) $id);
        }
        $db->commit();
        return count($ids);
    });
    echo "SACCO {$sacco['code']}: $count loans\n";
}
