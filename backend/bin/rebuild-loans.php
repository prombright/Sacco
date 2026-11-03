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

foreach (Database::platform()->query('SELECT code, db_name FROM saccos ORDER BY id') as $sacco) {
    $db = Database::connect($sacco['db_name']);
    $ids = $db->query("SELECT id FROM loans WHERE status IN ('active', 'closed')")->fetchAll(PDO::FETCH_COLUMN);
    $db->beginTransaction();
    foreach ($ids as $id) {
        Loans::applyPayments($db, (int) $id);
    }
    $db->commit();
    echo "SACCO {$sacco['code']}: " . count($ids) . " loans\n";
}
