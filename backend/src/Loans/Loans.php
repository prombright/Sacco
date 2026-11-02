<?php

declare(strict_types=1);

namespace Sacco\Loans;

use PDO;
use Sacco\Database;
use Sacco\Support\Ledger;
use Sacco\Support\Money;
use Sacco\Support\Settings;

/**
 * Loan rules: how much a member may borrow, how much of a guarantor's savings is tied up, moving
 * a loan along once its guarantors have agreed, and applying repayments to the schedule.
 */
final class Loans
{
    /** Loans that still count against the borrower's limit and tie up guarantors' savings */
    public const OPEN = ['pending_guarantors', 'pending_approval', 'approved', 'active'];

    /** Largest loan the system accepts, in cents (keeps the interest arithmetic well inside int range) */
    public const MAX_CENTS = 1_000_000_000_000;

    public static function rules(): array
    {
        return [
            'interest_rate_monthly' => Settings::get('loan_interest_rate_monthly', '1.0'),
            'interest_method' => Settings::get('loan_interest_method', 'reducing_balance'),
            'savings_multiplier' => Settings::get('loan_savings_multiplier', '3'),
            'max_months' => (int) Settings::get('loan_max_months', '24'),
            'min_guarantors' => (int) Settings::get('loan_min_guarantors', '2'),
            'guarantee_cover_percent' => (int) Settings::get('loan_guarantee_cover_percent', '100'),
        ];
    }

    /**
     * What a member can borrow now: multiplier x savings, minus what they owe or have applied for
     * (leaving out $exceptLoanId, when judging that loan itself).
     *
     * @return array{savings: int, owed: int, limit: int} cents
     */
    public static function eligibility(int $userId, ?int $exceptLoanId = null): array
    {
        $db = Database::tenant();
        $savings = Ledger::balance($db, $userId, 'savings');
        $owed = self::owedOnOpenLoans($db, $userId, $exceptLoanId);
        $multiplier = (int) Money::parse(self::rules()['savings_multiplier']); // "3" -> 300
        $limit = max(0, intdiv($savings * $multiplier, 100) - $owed);
        return ['savings' => $savings, 'owed' => $owed, 'limit' => $limit];
    }

    /** Principal still owed on active loans, plus the full amount of loans not yet disbursed */
    public static function owedOnOpenLoans(PDO $db, int $userId, ?int $exceptLoanId = null): int
    {
        $stmt = $db->prepare(
            "SELECT l.id, l.status, l.amount, COALESCE(SUM(i.principal - i.principal_paid), 0) AS principal_left
             FROM loans l LEFT JOIN loan_installments i ON i.loan_id = l.id
             WHERE l.user_id = ? AND l.status IN ('" . implode("','", self::OPEN) . "')
             GROUP BY l.id"
        );
        $stmt->execute([$userId]);
        $owed = 0;
        foreach ($stmt->fetchAll() as $loan) {
            if ((int) $loan['id'] === $exceptLoanId) {
                continue;
            }
            $owed += Money::fromDecimal($loan['status'] === 'active' ? $loan['principal_left'] : $loan['amount']);
        }
        return $owed;
    }

    /**
     * Savings a member has pledged and can't withdraw. An accepted pledge counts in full until the
     * loan is disbursed, then shrinks as the loan's principal is repaid.
     */
    public static function pledged(PDO $db, int $userId): int
    {
        $stmt = $db->prepare(
            "SELECT g.amount AS pledge, l.status, l.amount AS loan_amount,
                    (SELECT COALESCE(SUM(i.principal - i.principal_paid), 0) FROM loan_installments i WHERE i.loan_id = l.id) AS principal_left
             FROM loan_guarantors g JOIN loans l ON l.id = g.loan_id
             WHERE g.guarantor_id = ? AND g.status = 'accepted' AND l.status IN ('" . implode("','", self::OPEN) . "')"
        );
        $stmt->execute([$userId]);
        $total = 0;
        foreach ($stmt->fetchAll() as $row) {
            $pledge = Money::fromDecimal($row['pledge']);
            if ($row['status'] === 'active') {
                $loan = Money::fromDecimal($row['loan_amount']);
                // Float only for the ratio: pledge x principal could pass the int range
                $pledge = $loan > 0 ? (int) ceil($pledge * (Money::fromDecimal($row['principal_left']) / $loan)) : 0;
            }
            $total += $pledge;
        }
        return $total;
    }

    /** Savings minus pledges: what a member could withdraw or pledge again */
    public static function freeSavings(PDO $db, int $userId): int
    {
        return max(0, Ledger::balance($db, $userId, 'savings') - self::pledged($db, $userId));
    }

    /**
     * After a guarantor answers: once enough have accepted to cover the loan, it goes to the
     * admins for approval.
     */
    public static function advance(PDO $db, int $loanId): void
    {
        $loan = $db->prepare('SELECT * FROM loans WHERE id = ? FOR UPDATE');
        $loan->execute([$loanId]);
        $loan = $loan->fetch();
        if (!$loan || $loan['status'] !== 'pending_guarantors') {
            return;
        }
        if (self::guaranteeShortfall($db, $loan) === null) {
            $db->prepare("UPDATE loans SET status = 'pending_approval' WHERE id = ?")->execute([$loanId]);
        }
    }

    /**
     * What's still missing in guarantees, or null when the loan is covered.
     *
     * @return array{guarantors: int, amount: int}|null how many more guarantors / cents of cover are needed
     */
    public static function guaranteeShortfall(PDO $db, array $loan): ?array
    {
        $rules = self::rules();
        $stmt = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM loan_guarantors WHERE loan_id = ? AND status = 'accepted'");
        $stmt->execute([$loan['id']]);
        $accepted = $stmt->fetch();
        $needed = intdiv(Money::fromDecimal($loan['amount']) * $rules['guarantee_cover_percent'], 100);
        $missingPeople = max(0, $rules['min_guarantors'] - (int) $accepted['n']);
        $missingCover = max(0, $needed - Money::fromDecimal($accepted['total']));
        return $missingPeople === 0 && $missingCover === 0 ? null : ['guarantors' => $missingPeople, 'amount' => $missingCover];
    }

    /** Writes the schedule when the loan is disbursed */
    public static function createSchedule(PDO $db, array $loan, string $disbursedOn): void
    {
        $insert = $db->prepare('INSERT INTO loan_installments (loan_id, number, due_date, principal, interest) VALUES (?, ?, ?, ?, ?)');
        foreach (LoanMath::schedule(Money::fromDecimal($loan['amount']), $loan['interest_rate_monthly'], (int) $loan['months'], $disbursedOn) as $row) {
            $insert->execute([$loan['id'], $row['number'], $row['due_date'], Money::toDecimal($row['principal']), Money::toDecimal($row['interest'])]);
        }
    }

    /**
     * Works out what each instalment has had paid, from scratch, from all repayments that stand
     * (not reversed). Each repayment goes to the oldest unpaid instalment first: its interest, then
     * its principal. Closes the loan when everything is paid, reopens it if a reversal undoes that.
     */
    public static function applyPayments(PDO $db, int $loanId): void
    {
        $installments = $db->prepare('SELECT id, principal, interest FROM loan_installments WHERE loan_id = ? ORDER BY number');
        $installments->execute([$loanId]);
        $rows = array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'principal' => Money::fromDecimal($r['principal']),
            'interest' => Money::fromDecimal($r['interest']),
            'principal_paid' => 0,
            'interest_paid' => 0,
        ], $installments->fetchAll());

        $payments = $db->prepare(
            'SELECT p.amount, p.paid_on FROM loan_payments p
             WHERE p.loan_id = ? AND p.reversal_of IS NULL
               AND NOT EXISTS (SELECT 1 FROM loan_payments r WHERE r.reversal_of = p.id)
             ORDER BY p.paid_on, p.id'
        );
        $payments->execute([$loanId]);
        $lastPaidOn = null;
        foreach ($payments->fetchAll() as $payment) {
            $left = Money::fromDecimal($payment['amount']);
            $lastPaidOn = $payment['paid_on'];
            foreach ($rows as &$row) {
                foreach (['interest', 'principal'] as $part) {
                    $take = min($left, $row[$part] - $row[$part . '_paid']);
                    $row[$part . '_paid'] += $take;
                    $left -= $take;
                }
            }
            unset($row);
        }

        $update = $db->prepare('UPDATE loan_installments SET principal_paid = ?, interest_paid = ? WHERE id = ?');
        $allPaid = true;
        foreach ($rows as $row) {
            $update->execute([Money::toDecimal($row['principal_paid']), Money::toDecimal($row['interest_paid']), $row['id']]);
            $allPaid = $allPaid && $row['principal_paid'] === $row['principal'] && $row['interest_paid'] === $row['interest'];
        }

        if ($allPaid) {
            $db->prepare("UPDATE loans SET status = 'closed', closed_on = ? WHERE id = ? AND status = 'active'")->execute([$lastPaidOn, $loanId]);
        } else {
            $db->prepare("UPDATE loans SET status = 'active', closed_on = NULL WHERE id = ? AND status = 'closed'")->execute([$loanId]);
        }
    }

    /** Totals for a disbursed loan, in cents */
    public static function position(PDO $db, int $loanId): array
    {
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(principal), 0) AS principal, COALESCE(SUM(interest), 0) AS interest,
                    COALESCE(SUM(principal_paid), 0) AS principal_paid, COALESCE(SUM(interest_paid), 0) AS interest_paid
             FROM loan_installments WHERE loan_id = ?'
        );
        $stmt->execute([$loanId]);
        $t = array_map([Money::class, 'fromDecimal'], $stmt->fetch());

        $next = $db->prepare(
            'SELECT due_date, (principal + interest - principal_paid - interest_paid) AS due
             FROM loan_installments WHERE loan_id = ? AND principal + interest > principal_paid + interest_paid
             ORDER BY number LIMIT 1'
        );
        $next->execute([$loanId]);
        $next = $next->fetch() ?: null;

        $overdue = $db->prepare(
            'SELECT COALESCE(SUM(principal + interest - principal_paid - interest_paid), 0)
             FROM loan_installments WHERE loan_id = ? AND due_date < CURDATE()'
        );
        $overdue->execute([$loanId]);

        return [
            'principal_left' => $t['principal'] - $t['principal_paid'],
            'interest_left' => $t['interest'] - $t['interest_paid'],
            'total_left' => $t['principal'] + $t['interest'] - $t['principal_paid'] - $t['interest_paid'],
            'paid' => $t['principal_paid'] + $t['interest_paid'],
            'total_interest' => $t['interest'],
            'next_due_date' => $next['due_date'] ?? null,
            'next_due_amount' => $next ? Money::fromDecimal($next['due']) : 0,
            'overdue' => Money::fromDecimal($overdue->fetchColumn()),
        ];
    }
}
