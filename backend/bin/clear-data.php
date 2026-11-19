<?php

declare(strict_types=1);

/**
 * Clears one SACCO's data so real data can be brought in afresh - the members and everything
 * about their money - while keeping how the SACCO is set up. Nothing about the system changes:
 * no table, column or code is touched.
 *
 *   php backend/bin/clear-data.php --sacco=smacon
 *
 * It first saves a full backup (backend/storage/backups/before-clear-...sql) and stops if that
 * fails; then shows what it will clear and keep, and goes ahead only when you type the SACCO's
 * code and the word CLEAR. To undo it, load that backup into the database (phpMyAdmin > Import).
 *
 * KEPT: settings (the SACCO's rules), the chart of accounts, loan products, committee rules,
 *       budgets, the record of database updates, and the office logins with a password of their
 *       own (so the office can still log in; their link to a member account is dropped).
 * CLEARED: members (and members' office access: give it again after the import), next of kin,
 *       savings and shares entries, loans and all about them, fees, payroll uploads, withdrawals,
 *       transfers, dividends, investments, the cash book, journals and the books, bank
 *       reconciliations, closed years, savings interest, the old system's history, messages,
 *       notices, notifications, approvals, the audit log, login history.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../bootstrap.php';

use Sacco\Database;
use Sacco\Support\Backup;
use Sacco\Tenant;

const CLEAR = [
    // money and loans
    'transactions', 'transfers', 'withdrawal_requests', 'membership_fees', 'payroll_batches',
    'loans', 'loan_installments', 'loan_payments', 'loan_payment_allocations', 'loan_charges', 'loan_guarantors',
    'loan_securities', 'loan_reminders', 'loan_followups', 'approvals',
    'dividend_runs', 'investments', 'investment_returns', 'cashbook', 'journal_entries', 'journal_lines',
    'bank_reconciliations', 'bank_rec_items', 'closed_years', 'savings_interest', 'savings_interest_payouts',
    'member_exits', 'old_system_history', 'old_system_interest_payable',
    // people and messages
    'next_of_kin', 'notices', 'notifications', 'office_messages', 'password_history', 'password_resets',
    'login_log', 'login_throttle', 'audit_log',
];
// The system's own notes about the data, so the cleared SACCO starts clean
const RESET_SETTINGS = ['books_fingerprint', 'daily_run_on', 'savings_interest_last_month', 'loans_engine', 'backup_last_sent', 'backup_last_error'];

$code = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--sacco=')) {
        $code = substr($a, 8);
    }
}
if (!$code) {
    exit("Say which SACCO: php backend/bin/clear-data.php --sacco=CODE\n");
}
$stmt = Database::platform()->prepare('SELECT * FROM saccos WHERE code = ?');
$stmt->execute([$code]);
$sacco = $stmt->fetch() ?: exit("No SACCO with the code '$code'.\n");

Tenant::actAs($sacco, function (PDO $db) use ($sacco) {
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $clear = array_values(array_intersect(CLEAR, $tables));
    $count = fn (string $sql) => (int) $db->query($sql)->fetchColumn();

    echo "\nSACCO {$sacco['name']} ({$sacco['code']}), database {$sacco['db_name']}\n\n";
    echo "WILL BE CLEARED:\n";
    printf("  %-32s %s\n", 'members', $count("SELECT COUNT(*) FROM users WHERE role = 'member'"));
    printf("  %-32s %s\n", "members' office access", $count("SELECT COUNT(*) FROM users WHERE role <> 'member' AND office_via_member = 1"));
    foreach ($clear as $t) {
        printf("  %-32s %s\n", $t, $count("SELECT COUNT(*) FROM `$t`"));
    }
    echo "\nWILL BE KEPT:\n";
    printf("  %-32s %s\n", 'office logins', $count("SELECT COUNT(*) FROM users WHERE role <> 'member' AND office_via_member = 0"));
    foreach (['settings', 'gl_accounts', 'loan_products', 'committee_questions', 'budgets', 'schema_migrations'] as $t) {
        if (in_array($t, $tables, true)) {
            printf("  %-32s %s\n", $t, $count("SELECT COUNT(*) FROM `$t`"));
        }
    }

    // A full backup first: no backup, no clearing
    echo "\nSaving a backup first... ";
    $dir = __DIR__ . '/../storage/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $file = "$dir/before-clear-{$sacco['code']}-" . date('Y-m-d-His') . '.sql';
    try {
        $sql = Backup::sql();
    } catch (\Throwable $e) {
        exit("FAILED: " . $e->getMessage() . "\nNothing was cleared.\n");
    }
    if (file_put_contents($file, $sql) === false || filesize($file) < 1000) {
        exit("FAILED to write $file\nNothing was cleared.\n");
    }
    echo 'saved: ' . realpath($file) . ' (' . round(filesize($file) / 1048576, 1) . " MB)\n";

    echo "\nThis can't be undone except by loading that backup.\n";
    echo "Type the SACCO's code ({$sacco['code']}) to go on: ";
    if (trim((string) fgets(STDIN)) !== $sacco['code']) {
        exit("Stopped. Nothing was cleared.\n");
    }
    echo "Type CLEAR to clear the data: ";
    if (trim((string) fgets(STDIN)) !== 'CLEAR') {
        exit("Stopped. Nothing was cleared.\n");
    }

    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($clear as $t) {
            $db->exec("TRUNCATE TABLE `$t`");
        }
        // Members go, and the office access given to members; office logins stay, unlinked
        $db->exec("DELETE FROM users WHERE role = 'member' OR office_via_member = 1");
        $db->exec('UPDATE users SET linked_member_id = NULL, failed_attempts = 0, locked_until = NULL');
        $in = implode(',', array_map(fn ($k) => $db->quote($k), RESET_SETTINGS));
        $db->exec("DELETE FROM settings WHERE `key` IN ($in)");
    } finally {
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    // The audit log starts again with this
    $db->prepare('INSERT INTO audit_log (actor_id, action, details, ip) VALUES (NULL, ?, ?, ?)')
        ->execute(['data.cleared', json_encode(['backup' => basename($file)]), 'server']);

    $left = $count("SELECT COUNT(*) FROM users");
    echo "\nDone. Data cleared; $left office login(s) kept. The backup is $file\n";
    echo "Next: log in to the office and bring the real data in (members, the old system's records, transactions).\n";
});
