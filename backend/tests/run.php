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
        check("Carol's record shows the late repayments (2 late, 1 unpaid)", $standing['late'] >= 2 && $standing['overdue_now'] >= 1, "{$standing['late']} late, {$standing['overdue_now']} unpaid");
        check("...but the system doesn't mark her a slow payer (salaries pay, not her)", $standing['grade'] !== 'poor' && Credit::limitPercent($db, $carol) === 100, $standing['grade']);
        $usual = Loans::eligibility($carol)['limit'];
        // The office marks her (what POST /members/{id}/slow-payer does)
        $set->execute(['slow_payer_limit_percent', '50']);
        \Sacco\Support\Settings::forget();
        $db->prepare('UPDATE users SET slow_payer_since = CURDATE(), slow_payer_note = ? WHERE id = ?')->execute(['Test', $carol]);
        check('Marked by the office: a slow payer, borrowing 50% of the usual limit', Credit::standing($db, $carol)['grade'] === 'poor' && Credit::limitPercent($db, $carol) === 50, Credit::standing($db, $carol)['label']);
        check('...her limit is cut accordingly', Loans::eligibility($carol)['limit'] < $usual, ugx(Loans::eligibility($carol)['limit']) . ' < ' . ugx($usual));
        $db->prepare('UPDATE users SET slow_payer_since = NULL, slow_payer_note = NULL WHERE id = ?')->execute([$carol]);
        check('Mark lifted: the full limit is back', Credit::limitPercent($db, $carol) === 100 && Loans::eligibility($carol)['limit'] === $usual);
        $dave = $member('Dave Test', '904');
        $post($dave, 'savings', 'deposit', 500_000);
        // Dave: one instalment due so far, paid on its due date
        $good = $loanOf($dave, 600_000, 6, '1.00', $ago(40));
        $pay((int) $good['id'], $due($good, 1), $inst((int) $good['id'], 1)['due_date']);
        check('Paying on time keeps a good standing', Credit::standing($db, $dave)['grade'] === 'good', Credit::standing($db, $dave)['grade']);

        echo "\nCommittee rules (November 2026)\n";
        // Withdrawals: the fee on top, so the most you can ask for leaves room for it
        $set->execute(['withdrawal_fee_percent', '3']);
        $set->execute(['withdrawal_fee_mode', 'on_top']);
        \Sacco\Support\Settings::forget();
        $eve = $member('Eve Test', '905');
        $post($eve, 'savings', 'deposit', 1_000_000);
        $max = \Sacco\Controllers\WithdrawalController::maxRequest(Savings::withdrawable($db, $eve));
        $fits = fn (int $a) => $a + Savings::withdrawalFee($a) <= Savings::withdrawable($db, $eve);
        check('Fee on top: the most Eve can ask for (' . ugx($max) . ') plus its 3% fee fits in her 980,000', $fits($max) && !$fits($max + 100), ugx($max));
        check('...a 500,000 request costs 515,000 from savings and the cheque is the full 500,000', Savings::withdrawalFee(50_000_000) === 1_500_000);

        // Guarantors: only the part above the member's savings
        $rules = Loans::productRules($db, null);
        check('Saved 2,000,000, borrowing 4,000,000: guarantors cover only 2,000,000', Loans::coverNeeded(400_000_000, 200_000_000, $rules) === 200_000_000);
        check('...and one guarantor is enough (any number may join)', Loans::guarantorsNeeded(200_000_000, $rules) === 1);
        check('A loan no bigger than your savings needs no guarantor', Loans::coverNeeded(150_000_000, 200_000_000, $rules) === 0 && Loans::guarantorsNeeded(0, $rules) === 0);

        // The member's own savings stand behind their loan, less as it is repaid
        $fred = $member('Fred Test', '906');
        $post($fred, 'savings', 'deposit', 2_000_000);
        $own = $loanOf($fred, 4_000_000, 12, '1.00', date('Y-m-d', strtotime('-40 days')));
        $db->prepare('UPDATE loans SET self_cover = ? WHERE id = ?')->execute(['2000000.00', $own['id']]);
        check('Fred\'s 2,000,000 of savings is held behind his 4,000,000 loan', Loans::pledged($db, $fred) === 200_000_000, ugx(Loans::pledged($db, $fred)));
        $pay((int) $own['id'], intdiv($payment($inst((int) $own['id'], 1)), 100), $inst((int) $own['id'], 1)['due_date']);
        $heldNow = Loans::pledged($db, $fred);
        check('...and less is held once he repays (' . ugx($heldNow) . ' now)', $heldNow < 200_000_000 && $heldNow > 180_000_000, ugx($heldNow));
        check('Loans from the old system hold none of their borrower\'s savings', Loans::heldForOwnLoans($db, $brian) === 0);

        // The processing fee from savings: the cheque is the full loan, the fee is income in the books
        $db->beginTransaction();
        Ledger::post($db, $fred, 'savings', 'loan_fee', -1_000_000, ['value_date' => date('Y-m-d'), 'reference' => $own['loan_no'], 'created_by' => null]);
        $db->prepare('UPDATE loans SET processing_fee = ?, fee_from_savings = 1, source = ? WHERE id = ?')->execute(['10000.00', 'applied', $own['id']]);
        $db->commit();
        check('The 10,000 fee comes off Fred\'s savings', Ledger::balance($db, $fred, 'savings') === 199_000_000);

        // A mistake on the wrong member: hidden from the member, kept for the office
        $gina = $member('Gina Test', '907');
        $post($gina, 'savings', 'deposit', 300_000);
        $post($gina, 'savings', 'deposit', 500_000);
        $wrong = (int) $db->query("SELECT MAX(id) FROM transactions WHERE user_id = $gina")->fetchColumn();
        $db->beginTransaction();
        $fix = Ledger::reverse($db, $wrong, 'Meant for another member');
        $db->prepare('UPDATE transactions SET in_error = 1 WHERE id IN (?, ?)')->execute([$wrong, $fix['id']]);
        $db->commit();
        $seen = Ledger::history($gina, 'savings', 1, 50, true)['transactions'];
        $office = Ledger::history($gina, 'savings', 1, 50)['transactions'];
        check('An entry made by mistake and its correction are hidden from the member', count($seen) === 1 && count($office) === 3);
        check('...and her balance is right: 300,000', Ledger::balance($db, $gina, 'savings') === 30_000_000);

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
        $janLedger = Money::fromDecimal((string) $db->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account = 'savings' AND value_date <= '2026-01-31'")->fetchColumn());
        check('The January statements add up to all savings at the end of January', $sum === $janLedger, ugx($sum) . ' vs ' . ugx($janLedger));
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
