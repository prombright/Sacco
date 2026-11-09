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
            'savings_multiplier' => Settings::get('loan_savings_multiplier', '2'),
            'max_months' => (int) Settings::get('loan_max_months', '24'),
            'min_guarantors' => (int) Settings::get('loan_min_guarantors', '2'),
            'guarantee_cover_percent' => (int) Settings::get('loan_guarantee_cover_percent', '100'),
            'min_member_months' => (int) Settings::get('loan_min_member_months', '6'),
            'guarantor_min_member_months' => (int) Settings::get('guarantor_min_member_months', '6'),
        ];
    }

    /** Late-payment penalty, % of what is late ("0" = no penalties) */
    public static function penaltyPercent(): string
    {
        return Settings::get('loan_penalty_percent', '0');
    }

    /** The SACCO's loan products */
    public static function products(PDO $db, bool $activeOnly = true): array
    {
        $rows = $db->query('SELECT * FROM loan_products' . ($activeOnly ? " WHERE status = 'active'" : '') . ' ORDER BY id')->fetchAll();
        return array_map(fn ($p) => self::presentProduct($p), $rows);
    }

    public static function presentProduct(array $p): array
    {
        $trim = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
        return [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'description' => $p['description'],
            'interest_rate_monthly' => $trim($p['interest_rate_monthly']),
            'max_months' => (int) $p['max_months'],
            'savings_multiplier' => $trim($p['savings_multiplier']),
            'processing_fee_percent' => $trim($p['processing_fee_percent']) ?: '0',
            'min_guarantors' => (int) $p['min_guarantors'],
            'guarantee_cover_percent' => (int) $p['guarantee_cover_percent'],
            'min_amount' => $p['min_amount'] !== null ? $trim($p['min_amount']) : null,
            'max_amount' => $p['max_amount'] !== null ? $trim($p['max_amount']) : null,
            'min_member_months' => $p['min_member_months'] !== null ? (int) $p['min_member_months'] : null,
            'max_open' => (int) $p['max_open'],
            'open_months' => $p['open_months'] ? array_map('intval', explode(',', $p['open_months'])) : [],
            'allow_top_up' => (bool) $p['allow_top_up'],
            'status' => $p['status'],
        ];
    }

    /** A product's terms in the shape of rules(); the SACCO's general rules when there's no product */
    public static function productRules(PDO $db, ?int $productId): array
    {
        $rules = self::rules() + ['processing_fee_percent' => '0', 'product_id' => null, 'product_name' => null];
        if ($productId) {
            $stmt = $db->prepare('SELECT * FROM loan_products WHERE id = ?');
            $stmt->execute([$productId]);
            if ($row = $stmt->fetch()) {
                $p = self::presentProduct($row);
                $rules = array_merge($rules, [
                    'interest_rate_monthly' => $p['interest_rate_monthly'],
                    'max_months' => $p['max_months'],
                    'savings_multiplier' => $p['savings_multiplier'],
                    'min_guarantors' => $p['min_guarantors'],
                    'guarantee_cover_percent' => $p['guarantee_cover_percent'],
                    'processing_fee_percent' => $p['processing_fee_percent'],
                    'min_amount' => $p['min_amount'],
                    'max_amount' => $p['max_amount'],
                    'min_member_months' => $p['min_member_months'] ?? self::rules()['min_member_months'],
                    'max_open' => $p['max_open'],
                    'open_months' => $p['open_months'],
                    'allow_top_up' => $p['allow_top_up'],
                    'product_id' => $p['id'],
                    'product_name' => $p['name'],
                ]);
            }
        }
        return $rules;
    }

    /**
     * How long someone has been saving with the SACCO: from their "member since" date, or when an
     * admin hasn't set one, from their first savings entry that still stands (not reversed).
     *
     * @return array{since: ?string, months: int, eligible_on: ?string} eligible_on is filled in by the caller's rule
     */
    public static function membership(PDO $db, int $userId): array
    {
        $stmt = $db->prepare(
            "SELECT COALESCE(u.joined_on, (
                SELECT MIN(t.value_date) FROM transactions t
                WHERE t.user_id = u.id AND t.account = 'savings' AND t.type <> 'reversal'
                  AND NOT EXISTS (SELECT 1 FROM transactions r WHERE r.reversal_of = t.id)
             )) FROM users u WHERE u.id = ?"
        );
        $stmt->execute([$userId]);
        $since = $stmt->fetchColumn() ?: null;
        $months = 0;
        if ($since) {
            $diff = (new \DateTimeImmutable($since))->diff(new \DateTimeImmutable('today'));
            $months = $diff->invert ? 0 : $diff->y * 12 + $diff->m;
        }
        return ['since' => $since, 'months' => $months];
    }

    /** The day someone reaches $months of membership, or null if they haven't started saving */
    public static function eligibleOn(?string $since, int $months): ?string
    {
        return $since ? LoanMath::addMonths($since, $months) : null;
    }

    /** Null when the member has saved long enough to borrow, else why not (for the member) */
    public static function borrowerWaitReason(PDO $db, int $userId, ?int $need = null): ?string
    {
        $need ??= self::rules()['min_member_months'];
        $m = self::membership($db, $userId);
        if ($m['months'] >= $need) {
            return null;
        }
        return $m['since']
            ? "Loans are for members who have saved for $need months. You can apply from " . date('j M Y', strtotime(self::eligibleOn($m['since'], $need))) . '.'
            : "Loans are for members who have saved for $need months. Your savings haven't started yet.";
    }

    /** Null when someone has been a member long enough to guarantee, else why not */
    public static function guarantorWaitReason(PDO $db, int $userId, string $name, bool $self = false): ?string
    {
        $need = self::rules()['guarantor_min_member_months'];
        if (self::membership($db, $userId)['months'] >= $need) {
            return null;
        }
        return $self
            ? "Guarantors must have been members for $need months, and you haven't yet."
            : "$name has been a member for less than $need months, so can't be a guarantor yet.";
    }

    /**
     * What a member can borrow now: multiplier x savings, minus what they owe or have applied for
     * (leaving out $exceptLoanId, when judging that loan itself).
     *
     * @return array{savings: int, owed: int, limit: int, limit_percent: int} cents (limit_percent: 100, or less for a slow payer)
     */
    public static function eligibility(int $userId, ?int $exceptLoanId = null, ?int $productId = null): array
    {
        $db = Database::tenant();
        $savings = Ledger::balance($db, $userId, 'savings');
        $owed = self::owedOnOpenLoans($db, $userId, $exceptLoanId);
        $multiplier = (int) Money::parse(self::productRules($db, $productId)['savings_multiplier']); // "2" -> 200
        // A slow payer may borrow only part of the usual limit (see Credit)
        $percent = Credit::limitPercent($db, $userId);
        $limit = max(0, intdiv(intdiv($savings * $multiplier, 100) * $percent, 100) - $owed);
        return ['savings' => $savings, 'owed' => $owed, 'limit' => $limit, 'limit_percent' => $percent];
    }

    /** Why a member can't apply for this type of loan now, or null */
    public static function productBlock(PDO $db, int $userId, array $rules, ?int $replacing = null): ?string
    {
        if ($wait = self::borrowerWaitReason($db, $userId, (int) $rules['min_member_months'])) {
            return $wait;
        }
        $months = $rules['open_months'] ?? [];
        if ($months && !in_array((int) date('n'), $months, true)) {
            $names = array_map(fn ($m) => date('F', mktime(0, 0, 0, $m, 1)), $months);
            return "{$rules['product_name']} can be applied for in " . implode(', ', $names) . ' only.';
        }
        $open = $db->prepare("SELECT COUNT(*) FROM loans WHERE user_id = ? AND product_id = ? AND status IN ('pending_guarantors', 'pending_approval', 'approved', 'active') AND id <> ?");
        $open->execute([$userId, $rules['product_id'], $replacing ?? 0]);
        $max = (int) ($rules['max_open'] ?? 1);
        if ($max > 0 && (int) $open->fetchColumn() >= $max) {
            return "You already have the most {$rules['product_name']}s you can hold at once ($max). Repay one, or top it up if that's allowed.";
        }
        return null;
    }

    /** The member's active loans that a new loan of this type can top up, with what clearing each costs */
    public static function topUps(PDO $db, int $userId, array $rules): array
    {
        if (empty($rules['allow_top_up'])) {
            return [];
        }
        $stmt = $db->prepare("SELECT id, loan_no, amount FROM loans WHERE user_id = ? AND product_id = ? AND status = 'active' ORDER BY id");
        $stmt->execute([$userId, $rules['product_id']]);
        return array_map(fn ($l) => ['id' => (int) $l['id'], 'loan_no' => $l['loan_no'], 'amount' => $l['amount'],
            'to_clear' => Money::toDecimal(self::settlementQuote($db, (int) $l['id'])['amount'])], $stmt->fetchAll());
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
        return array_sum(array_map(fn ($p) => $p['held'], self::pledges($db, $userId)));
    }

    /**
     * Each guarantee the member stands behind now, keyed by guarantee id: what they pledged, what
     * is still held (cents) and how much of the loan's principal is repaid (percent).
     *
     * @return array<int, array{pledge: int, held: int, repaid_percent: int}>
     */
    public static function pledges(PDO $db, int $userId): array
    {
        $stmt = $db->prepare(
            "SELECT g.id, g.amount AS pledge, l.status, l.amount AS loan_amount,
                    (SELECT COALESCE(SUM(i.principal - i.principal_paid), 0) FROM loan_installments i WHERE i.loan_id = l.id) AS principal_left
             FROM loan_guarantors g JOIN loans l ON l.id = g.loan_id
             WHERE g.guarantor_id = ? AND g.status = 'accepted' AND l.status IN ('" . implode("','", self::OPEN) . "')"
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $pledge = $held = Money::fromDecimal($row['pledge']);
            $repaid = 0;
            if ($row['status'] === 'active') {
                $loan = Money::fromDecimal($row['loan_amount']);
                $ratio = $loan > 0 ? Money::fromDecimal($row['principal_left']) / $loan : 0;
                // Float only for the ratio (pledge x principal could pass the int range); rounded up
                // to whole currency units so a pledge never shows stray cents
                $held = (int) (ceil($pledge * $ratio / LoanMath::UNIT) * LoanMath::UNIT);
                $repaid = (int) floor((1 - $ratio) * 100);
            }
            $out[(int) $row['id']] = ['pledge' => $pledge, 'held' => $held, 'repaid_percent' => $repaid];
        }
        return $out;
    }

    /** A loan is fully repaid: its guarantors' savings are free again, and they're told so */
    public static function releaseGuarantors(PDO $db, int $loanId): void
    {
        $stmt = $db->prepare(
            "SELECT g.guarantor_id, g.amount, l.loan_no, u.full_name FROM loan_guarantors g
             JOIN loans l ON l.id = g.loan_id JOIN users u ON u.id = l.user_id
             WHERE g.loan_id = ? AND g.status = 'accepted'"
        );
        $stmt->execute([$loanId]);
        foreach ($stmt->fetchAll() as $g) {
            \Sacco\Support\Notify::member((int) $g['guarantor_id'], 'guarantee_released',
                'You are released as a guarantor',
                "{$g['full_name']} has fully repaid loan {$g['loan_no']}. The " . \Sacco\Support\Notify::money($g['amount'])
                    . ' you pledged is no longer held, so you can withdraw it or guarantee someone else.',
                '/loans');
        }
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
        $rules = self::productRules($db, isset($loan['product_id']) ? (int) $loan['product_id'] : null);
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
     * Works out, from scratch, what every charge and instalment has had paid, from all repayments
     * that stand (not reversed), and records how each repayment was split. Each repayment clears
     * unpaid charges (penalties) first, oldest first, then the oldest unpaid instalment: its
     * interest, then its principal. Closes the loan when everything is paid; reopens it if a
     * reversal undoes that.
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
            'cleared_on' => null,
        ], $installments->fetchAll());

        $charges = $db->prepare('SELECT id, type, amount, charged_on FROM loan_charges WHERE loan_id = ? AND waived_at IS NULL ORDER BY charged_on, id');
        $charges->execute([$loanId]);
        $charges = array_map(fn ($c) => ['id' => (int) $c['id'], 'type' => $c['type'], 'amount' => Money::fromDecimal($c['amount']), 'charged_on' => $c['charged_on'], 'paid' => 0], $charges->fetchAll());

        $payments = $db->prepare(
            'SELECT p.id, p.amount, p.paid_on FROM loan_payments p
             WHERE p.loan_id = ? AND p.reversal_of IS NULL
               AND NOT EXISTS (SELECT 1 FROM loan_payments r WHERE r.reversal_of = p.id)
             ORDER BY p.paid_on, p.id'
        );
        $payments->execute([$loanId]);
        $db->prepare('DELETE FROM loan_payment_allocations WHERE loan_id = ?')->execute([$loanId]);
        $allocate = $db->prepare('INSERT INTO loan_payment_allocations (payment_id, loan_id, part, amount) VALUES (?, ?, ?, ?)');

        $lastPaidOn = null;
        foreach ($payments->fetchAll() as $payment) {
            $left = Money::fromDecimal($payment['amount']);
            $lastPaidOn = $payment['paid_on'];
            $split = ['penalty' => 0, 'fee' => 0, 'interest' => 0, 'principal' => 0];
            foreach ($charges as &$charge) {
                // A repayment only clears charges that already existed when it was made
                if ($charge['charged_on'] > $payment['paid_on']) {
                    continue;
                }
                $take = min($left, $charge['amount'] - $charge['paid']);
                $charge['paid'] += $take;
                $split[$charge['type']] += $take;
                $left -= $take;
            }
            unset($charge);
            foreach ($rows as &$row) {
                foreach (['interest', 'principal'] as $part) {
                    $take = min($left, $row[$part] - $row[$part . '_paid']);
                    $row[$part . '_paid'] += $take;
                    $split[$part] += $take;
                    $left -= $take;
                }
                if ($row['cleared_on'] === null && $row['principal_paid'] === $row['principal'] && $row['interest_paid'] === $row['interest']) {
                    $row['cleared_on'] = $payment['paid_on'];
                }
            }
            unset($row);
            foreach ($split as $part => $cents) {
                if ($cents > 0) {
                    $allocate->execute([$payment['id'], $loanId, $part, Money::toDecimal($cents)]);
                }
            }
        }

        $update = $db->prepare('UPDATE loan_installments SET principal_paid = ?, interest_paid = ?, cleared_on = ? WHERE id = ?');
        $allPaid = true;
        foreach ($rows as $row) {
            $update->execute([Money::toDecimal($row['principal_paid']), Money::toDecimal($row['interest_paid']), $row['cleared_on'], $row['id']]);
            $allPaid = $allPaid && $row['principal_paid'] === $row['principal'] && $row['interest_paid'] === $row['interest'];
        }
        $updateCharge = $db->prepare('UPDATE loan_charges SET paid = ? WHERE id = ?');
        foreach ($charges as $charge) {
            $updateCharge->execute([Money::toDecimal($charge['paid']), $charge['id']]);
            $allPaid = $allPaid && $charge['paid'] === $charge['amount'];
        }

        if ($allPaid) {
            $close = $db->prepare("UPDATE loans SET status = 'closed', closed_on = ? WHERE id = ? AND status = 'active'");
            $close->execute([$lastPaidOn, $loanId]);
            if ($close->rowCount() > 0) {
                self::releaseGuarantors($db, $loanId);
            }
        } else {
            $db->prepare("UPDATE loans SET status = 'active', closed_on = NULL WHERE id = ? AND status = 'closed'")->execute([$loanId]);
        }

        // Repayments (and reversals of them) change how well the borrower repays
        $borrower = $db->prepare('SELECT user_id FROM loans WHERE id = ?');
        $borrower->execute([$loanId]);
        Credit::refresh($db, (int) $borrower->fetchColumn());
    }

    /** Totals for a disbursed loan, in cents. Unpaid charges (penalties) count as owed and overdue. */
    public static function position(PDO $db, int $loanId): array
    {
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(principal), 0) AS principal, COALESCE(SUM(interest), 0) AS interest,
                    COALESCE(SUM(principal_paid), 0) AS principal_paid, COALESCE(SUM(interest_paid), 0) AS interest_paid
             FROM loan_installments WHERE loan_id = ?'
        );
        $stmt->execute([$loanId]);
        $t = array_map([Money::class, 'fromDecimal'], $stmt->fetch());

        $c = $db->prepare('SELECT COALESCE(SUM(amount - paid), 0) FROM loan_charges WHERE loan_id = ? AND waived_at IS NULL');
        $c->execute([$loanId]);
        $chargesLeft = Money::fromDecimal($c->fetchColumn());

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
        $overdueInstalments = Money::fromDecimal($overdue->fetchColumn());

        return [
            'principal_left' => $t['principal'] - $t['principal_paid'],
            'interest_left' => $t['interest'] - $t['interest_paid'],
            'charges_left' => $chargesLeft,
            'total_left' => $t['principal'] + $t['interest'] - $t['principal_paid'] - $t['interest_paid'] + $chargesLeft,
            'paid' => $t['principal_paid'] + $t['interest_paid'],
            'total_interest' => $t['interest'],
            'next_due_date' => $next['due_date'] ?? null,
            'next_due_amount' => ($next ? Money::fromDecimal($next['due']) : 0) + $chargesLeft,
            // Penalties are charged on late instalments only; unpaid charges are added for display
            'overdue_instalments' => $overdueInstalments,
            'overdue' => $overdueInstalments + $chargesLeft,
        ];
    }

    /**
     * Paying the loan off today: the principal still owed, unpaid charges, and the interest of
     * instalments already due plus the month now running - not the months after it.
     *
     * @return array{amount: int, interest_saved: int}
     */
    public static function settlementQuote(PDO $db, int $loanId): array
    {
        $p = self::position($db, $loanId);
        $stmt = $db->prepare(
            'SELECT interest - interest_paid FROM loan_installments
             WHERE loan_id = ? AND principal + interest > principal_paid + interest_paid ORDER BY number'
        );
        $stmt->execute([$loanId]);
        $interestDue = array_map([Money::class, 'fromDecimal'], $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $late = $db->prepare('SELECT COUNT(*) FROM loan_installments WHERE loan_id = ? AND due_date < CURDATE() AND principal + interest > principal_paid + interest_paid');
        $late->execute([$loanId]);
        $keep = (int) $late->fetchColumn() + 1;
        $interestKept = array_sum(array_slice($interestDue, 0, $keep));
        return [
            'amount' => $p['principal_left'] + $p['charges_left'] + $interestKept,
            'interest_saved' => array_sum($interestDue) - $interestKept,
            'keep' => $keep,
        ];
    }

    /**
     * Before an early settlement payment: interest for the months the member will no longer borrow
     * is taken off the schedule. The first $keep unpaid instalments (late ones and the month now
     * running) keep their interest. Returns the cents waived.
     */
    public static function waiveFutureInterest(PDO $db, int $loanId, int $keep): int
    {
        $stmt = $db->prepare(
            'SELECT id, interest, interest_paid FROM loan_installments
             WHERE loan_id = ? AND principal + interest > principal_paid + interest_paid ORDER BY number'
        );
        $stmt->execute([$loanId]);
        $waived = 0;
        $update = $db->prepare('UPDATE loan_installments SET interest = interest_paid WHERE id = ?');
        foreach (array_slice($stmt->fetchAll(), $keep) as $i) {
            $waived += Money::fromDecimal($i['interest']) - Money::fromDecimal($i['interest_paid']);
            $update->execute([$i['id']]);
        }
        return $waived;
    }
}
