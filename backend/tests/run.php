<?php

declare(strict_types=1);

/**
 * Checks on the money rules, run against a brand-new throwaway database that is deleted at the
 * end (whatever happens). Never touches a real SACCO's data.
 *
 *   php backend/tests/run.php
 *
 * Prints a tick or a cross for each check and exits with 1 when any fails, so it can run before
 * every push.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

// Our own database and no email, whatever .env says (the environment wins over .env)
$testDb = 'sacco_test_' . getmypid();
putenv("PLATFORM_DB_NAME=$testDb");
putenv('MAIL_DRIVER=off');
require __DIR__ . '/../bootstrap.php';

use Sacco\Database;
use Sacco\Loans\Credit;
use Sacco\Loans\Loans;
use Sacco\Loans\Statements;
use Sacco\Support\Books;
use Sacco\Support\Ledger;
use Sacco\Support\LedgerException;
use Sacco\Support\Money;
use Sacco\Support\Notify;
use Sacco\Support\SaccoSetup;
use Sacco\Support\Savings;
use Sacco\Tenant;

$passed = 0;
$failed = [];
function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  \u{2713} $what\n";
    } else {
        $failed[] = $what;
        echo "  \u{2717} $what" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}
function ugx(int $cents): string
{
    return number_format($cents / 100);
}

$server = new PDO(sprintf('mysql:host=%s;port=%d', getenv('DB_HOST') ?: Sacco\Config::get('DB_HOST', '127.0.0.1'), (int) Sacco\Config::get('DB_PORT', 3306)),
    (string) Sacco\Config::get('DB_USER', 'root'), (string) Sacco\Config::get('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "Money rules, on a throwaway database ($testDb)\n";
try {
    $made = SaccoSetup::create(['code' => 'test', 'name' => 'Test SACCO', 'admin_name' => 'Test Admin', 'admin_email' => 'admin@test.local']);
    $sacco = Database::platform()->query("SELECT * FROM saccos WHERE code = 'test'")->fetch();
    Notify::$muted = true;

    Tenant::actAs($sacco, function (PDO $db) {
        $set = $db->prepare('INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        $set->execute(['savings_min_balance', '20000']);
        \Sacco\Support\Settings::forget();

        $member = function (string $name, string $acc) use ($db): int {
            $db->prepare("INSERT INTO users (full_name, role, status, password_hash, account_no, employee_no, joined_on) VALUES (?, 'member', 'active', 'x', ?, ?, '2020-01-01')")
                ->execute([$name, $acc, "T$acc"]);
            return (int) $db->lastInsertId();
        };
        $post = function (int $user, string $account, string $type, int $ugx, string $date = '2026-01-15') use ($db): void {
            $db->beginTransaction();
            try {
                Ledger::post($db, $user, $account, $type, $ugx * 100, ['value_date' => $date, 'reference' => 'Test', 'created_by' => null]);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }
        };
        $loanOf = function (int $user, int $ugx, int $months, string $rate, string $on) use ($db): array {
            $product = (int) ($db->query('SELECT id FROM loan_products ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            $db->prepare("INSERT INTO loans (user_id, product_id, source, amount, months, interest_rate_monthly, interest_method, purpose, status, decided_at, disbursed_on, disbursement_ref)
                          VALUES (?, ?, 'old_records', ?, ?, ?, 'reducing_balance', 'Test', 'active', NOW(), ?, 'TEST')")
                ->execute([$user, $product ?: null, Money::toDecimal($ugx * 100), $months, $rate, $on]);
            $id = (int) $db->lastInsertId();
            $db->prepare('UPDATE loans SET loan_no = ? WHERE id = ?')->execute(['L' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
            $loan = $db->query("SELECT * FROM loans WHERE id = $id")->fetch();
            Loans::createSchedule($db, $loan, $on);
            return $loan;
        };
        $pay = function (int $loanId, int $ugx, string $on, ?int $reversalOf = null) use ($db): int {
            $db->prepare('INSERT INTO loan_payments (loan_id, amount, paid_on, reference, reversal_of) VALUES (?, ?, ?, ?, ?)')
                ->execute([$loanId, Money::toDecimal($ugx * 100), $on, 'Test', $reversalOf]);
            $id = (int) $db->lastInsertId();
            Loans::applyPayments($db, $loanId);
            return $id;
        };
        $inst = fn (int $loanId, int $n) => $db->query("SELECT * FROM loan_installments WHERE loan_id = $loanId AND number = $n")->fetch();

        echo "\nSavings\n";
        $amina = $member('Amina Test', '901');
        $post($amina, 'savings', 'deposit', 1_000_000);
        check('A deposit adds to savings', Ledger::balance($db, $amina, 'savings') === 100_000_000);
        $threw = false;
        try {
            $post($amina, 'savings', 'withdrawal', -2_000_000);
        } catch (LedgerException) {
            $threw = true;
        }
        check('Savings can never go below zero', $threw && Ledger::balance($db, $amina, 'savings') === 100_000_000);
        check('The minimum balance (20,000) stays: 980,000 can be withdrawn', Savings::withdrawable($db, $amina) === 98_000_000, ugx(Savings::withdrawable($db, $amina)));

        echo "\nLoan schedule (1,200,000 over 12 months at 1% a month, reducing balance)\n";
        $brian = $member('Brian Test', '902');
        $post($brian, 'savings', 'deposit', 800_000);
        $loan = $loanOf($brian, 1_200_000, 12, '1.00', '2026-01-31');
        $rows = $db->query("SELECT * FROM loan_installments WHERE loan_id = {$loan['id']} ORDER BY number")->fetchAll();
        $principal = array_sum(array_map(fn ($r) => Money::fromDecimal($r['principal']), $rows));
        $interest = array_sum(array_map(fn ($r) => Money::fromDecimal($r['interest']), $rows));
        check('12 instalments', count($rows) === 12);
        check('The principal adds up to exactly the amount lent', $principal === 120_000_000, ugx($principal));
        check('First month\'s interest is 1% of 1,200,000 = 12,000', Money::fromDecimal($rows[0]['interest']) === 1_200_000, $rows[0]['interest']);
        check('Interest falls every month as the balance falls', $rows[11]['interest'] < $rows[0]['interest'] && $rows[6]['interest'] < $rows[5]['interest']);
        $payment = fn ($r) => Money::fromDecimal($r['principal']) + Money::fromDecimal($r['interest']);
        $firstEleven = array_unique(array_map($payment, array_slice($rows, 0, 11)));
        check('Every monthly payment is the same (106,619), the last one evens out', count($firstEleven) === 1 && abs(reset($firstEleven) - 10_661_900) <= 100, ugx((int) reset($firstEleven)));
        check('Total interest is about 79,400', abs($interest - 7_942_300) <= 1_000, ugx($interest));

        echo "\nRepayments\n";
        $first = $payment($rows[0]);
        $pay((int) $loan['id'], intdiv($first, 100), '2026-02-27');
        $i1 = $inst((int) $loan['id'], 1);
        check('Paying the first instalment clears it, dated the day it was paid', $i1['cleared_on'] === '2026-02-27' && Money::fromDecimal($i1['principal_paid']) === Money::fromDecimal($i1['principal']));
        $p = Loans::position($db, (int) $loan['id']);
        check('What is left = total to repay - what was paid', $p['total_left'] === $principal + $interest - $first, ugx($p['total_left']));
        $small = $pay((int) $loan['id'], 5_000, '2026-03-10');
        $i2 = $inst((int) $loan['id'], 2);
        check('A payment smaller than the interest goes to interest first', Money::fromDecimal($i2['interest_paid']) === 500_000 && Money::fromDecimal($i2['principal_paid']) === 0);
        $pay((int) $loan['id'], -5_000, '2026-03-11', $small);
        $i2 = $inst((int) $loan['id'], 2);
        check('Reversing that payment puts the instalment back as it was', Money::fromDecimal($i2['interest_paid']) === 0);

        echo "\nGuarantees\n";
        $db->prepare("INSERT INTO loan_guarantors (loan_id, guarantor_id, amount, requested_amount, status, responded_at) VALUES (?, ?, 600000, 600000, 'accepted', NOW())")->execute([$loan['id'], $amina]);
        // The hold shrinks as the borrower repays: 600,000 x the share of the principal still owed
        $owed = Money::fromDecimal((string) $db->query("SELECT SUM(principal - principal_paid) FROM loan_installments WHERE loan_id = {$loan['id']}")->fetchColumn());
        $held = intdiv(60_000_000 * $owed, 120_000_000);
        check('A guarantee holds the guarantor\'s savings, less as the borrower repays (' . ugx($held) . ' held now)', abs(Loans::pledged($db, $amina) - $held) <= 100, ugx(Loans::pledged($db, $amina)));
        check('...so Amina can withdraw only what is not held', abs(Savings::withdrawable($db, $amina) - (98_000_000 - $held)) <= 100, ugx(Savings::withdrawable($db, $amina)));
        $left = Loans::position($db, (int) $loan['id'])['total_left'];
        $pay((int) $loan['id'], intdiv($left, 100) + 1, '2026-04-30');
        $status = $db->query("SELECT status FROM loans WHERE id = {$loan['id']}")->fetchColumn();
        check('Paying everything left closes the loan', $status === 'closed');
        check('A closed loan frees the guarantee: Amina can withdraw 980,000 again', Savings::withdrawable($db, $amina) === 98_000_000, ugx(Savings::withdrawable($db, $amina)));

        echo "\nCredit standing\n";
        $carol = $member('Carol Test', '903');
        $post($carol, 'savings', 'deposit', 500_000);
        $ago = fn (int $days) => date('Y-m-d', strtotime("-$days days"));
        $after = fn (string $date, int $days) => date('Y-m-d', strtotime("$date +$days days"));
        // Carol: two instalments paid over a month late, a third due and still unpaid
        $late = $loanOf($carol, 600_000, 6, '1.00', $ago(110));
        $due = fn (array $l, int $n) => intdiv($payment($inst((int) $l['id'], $n)), 100);
        $pay((int) $late['id'], $due($late, 1), $after($inst((int) $late['id'], 1)['due_date'], 40));
        $pay((int) $late['id'], $due($late, 2), min(date('Y-m-d'), $after($inst((int) $late['id'], 2)['due_date'], 40)));
        $standing = Credit::standing($db, $carol);
        check('Paying over a month late, with one still unpaid, lowers the credit standing', in_array($standing['grade'], ['fair', 'poor'], true), $standing['grade'] . ' ' . $standing['score']);
        $dave = $member('Dave Test', '904');
        $post($dave, 'savings', 'deposit', 500_000);
        // Dave: one instalment due so far, paid on its due date
        $good = $loanOf($dave, 600_000, 6, '1.00', $ago(40));
        $pay((int) $good['id'], $due($good, 1), $inst((int) $good['id'], 1)['due_date']);
        check('Paying on time keeps a good standing', Credit::standing($db, $dave)['grade'] === 'good', Credit::standing($db, $dave)['grade']);

        echo "\nBooks\n";
        Books::sync($db);
        $dr = $cr = 0;
        foreach (Books::totals($db, date('Y-m-d')) as $t) {
            $dr += $t['d'];
            $cr += $t['c'];
        }
        check('The books balance: debits = credits', $dr === $cr && $dr > 0, ugx($dr) . ' / ' . ugx($cr));
        $savingsAccount = Books::account($db, 'member_savings');
        $s = $db->prepare('SELECT COALESCE(SUM(credit - debit), 0) FROM journal_lines WHERE account_id = ?');
        $s->execute([$savingsAccount]);
        $inBooks = Money::fromDecimal((string) $s->fetchColumn());
        $inLedger = Money::fromDecimal((string) $db->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account = 'savings'")->fetchColumn());
        check("Members' savings in the books = every member's savings added up", $inBooks === $inLedger, ugx($inBooks) . ' vs ' . ugx($inLedger));

        echo "\nMonthly statements\n";
        $all = Statements::summaries($db, '2026-01');
        $sum = array_sum(array_column($all, 'savings_end'));
        check('The January statements add up to all savings at the end of January', $sum === $inLedger, ugx($sum));
        $a = array_values(array_filter($all, fn ($m) => $m['user_id'] === $amina))[0] ?? null;
        check('A member\'s statement shows what they saved that month', $a && $a['savings_in'] === 100_000_000 && $a['savings_out'] === 0);
    });
} catch (\Throwable $e) {
    $failed[] = 'Unexpected error';
    echo "\n  \u{2717} Unexpected error: " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS `$testDb`");
}

echo "\n" . ($failed ? count($failed) . " FAILED, $passed passed" : "All $passed checks passed") . "\n";
exit($failed ? 1 : 0);
