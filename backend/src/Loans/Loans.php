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
            'sacco_max_months' => (int) Settings::get('loan_max_months', '24'),
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
            'processing_fee_type' => $p['processing_fee_type'] ?? 'flat',
            'processing_fee' => $trim($p['processing_fee']) ?: '0',
            'processing_fee_percent' => $trim($p['processing_fee_percent'] ?? '0') ?: '0',
            'processing_fee_bands' => json_decode((string) ($p['processing_fee_bands'] ?? ''), true) ?: [],
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

    /** The processing fee on a loan of $amount cents under these rules (a product's, from productRules) */
    public static function processingFee(array $rules, int $amount): int
    {
        switch ($rules['processing_fee_type'] ?? 'flat') {
            case 'percent':
                $hundredths = (int) Money::parse((string) ($rules['processing_fee_percent'] ?? '0'));
                return (int) (round($amount * ($hundredths / 10000) / LoanMath::UNIT) * LoanMath::UNIT);
            case 'bands':
                foreach ($rules['processing_fee_bands'] ?? [] as $b) {
                    $from = Money::parse((string) $b['from']) ?? 0;
                    $to = ($b['to'] ?? null) === null || $b['to'] === '' ? null : Money::parse((string) $b['to']);
                    if ($amount >= $from && ($to === null || $amount <= $to)) {
                        return Money::parse((string) $b['fee']) ?? 0;
                    }
                }
                return 0;
            default:
                return Money::parse((string) ($rules['processing_fee'] ?? '0')) ?? 0;
        }
    }

    /** A product's terms in the shape of rules(); the SACCO's general rules when there's no product */
    public static function productRules(PDO $db, ?int $productId): array
    {
        $rules = self::rules() + ['processing_fee_type' => 'flat', 'processing_fee' => '0', 'processing_fee_percent' => '0', 'processing_fee_bands' => [], 'product_id' => null, 'product_name' => null];
        if ($productId) {
            $stmt = $db->prepare('SELECT * FROM loan_products WHERE id = ?');
            $stmt->execute([$productId]);
            if ($row = $stmt->fetch()) {
                $p = self::presentProduct($row);
                $rules = array_merge($rules, [
                    'interest_rate_monthly' => $p['interest_rate_monthly'],
                    // A loan type's own longest term, never past the SACCO's longest (the committee: 24)
                    'max_months' => min((int) $p['max_months'], self::rules()['max_months']),
                    'savings_multiplier' => $p['savings_multiplier'],
                    'min_guarantors' => $p['min_guarantors'],
                    'guarantee_cover_percent' => $p['guarantee_cover_percent'],
                    'processing_fee_type' => $p['processing_fee_type'],
                    'processing_fee' => $p['processing_fee'],
                    'processing_fee_percent' => $p['processing_fee_percent'],
                    'processing_fee_bands' => $p['processing_fee_bands'],
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
        return array_sum(array_map(fn ($p) => $p['held'], self::pledges($db, $userId))) + self::heldForOwnLoans($db, $userId);
    }

    /**
     * Savings standing behind the member's own loans: the part their savings covered when they
     * applied, held while the loan runs and less as it is repaid. (Loans from the old system have
     * none, so their borrowers' savings aren't suddenly frozen.)
     */
    public static function heldForOwnLoans(PDO $db, int $userId): int
    {
        $stmt = $db->prepare(
            "SELECT l.status, l.amount, l.self_cover,
                    (SELECT COALESCE(SUM(i.principal - i.principal_paid), 0) FROM loan_installments i WHERE i.loan_id = l.id) AS principal_left
             FROM loans l WHERE l.user_id = ? AND l.self_cover > 0 AND l.status IN ('" . implode("','", self::OPEN) . "')"
        );
        $stmt->execute([$userId]);
        $held = 0;
        foreach ($stmt->fetchAll() as $l) {
            $cover = Money::fromDecimal($l['self_cover']);
            if ($l['status'] === 'active') {
                $loan = Money::fromDecimal($l['amount']);
                $cover = $loan > 0 ? (int) (ceil($cover * (Money::fromDecimal($l['principal_left']) / $loan) / LoanMath::UNIT) * LoanMath::UNIT) : 0;
            }
            $held += $cover;
        }
        return $held;
    }

    /** The part of a loan guarantors must cover: what the member's savings don't, at the cover rate */
    public static function coverNeeded(int $amount, int $selfCover, array $rules): int
    {
        return intdiv(max(0, $amount - $selfCover) * (int) $rules['guarantee_cover_percent'], 100);
    }

    /** How many guarantors at least: none when savings cover the loan, otherwise one (or the type's minimum) */
    public static function guarantorsNeeded(int $coverNeeded, array $rules): int
    {
        return $coverNeeded > 0 ? max(1, (int) $rules['min_guarantors']) : 0;
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
        // Security left with the SACCO can now go back to the borrower
        $s = $db->prepare("SELECT s.kind, s.description, l.loan_no, u.full_name FROM loan_securities s JOIN loans l ON l.id = s.loan_id
                           JOIN users u ON u.id = l.user_id WHERE s.loan_id = ? AND s.returned_on IS NULL");
        $s->execute([$loanId]);
        $held = $s->fetchAll();
        if ($held) {
            $what = implode('; ', array_map(fn ($r) => "{$r['kind']}: {$r['description']}", $held));
            \Sacco\Support\Notify::office('loan_security', 'Return a loan security',
                "{$held[0]['full_name']} has fully repaid loan {$held[0]['loan_no']}. Hand back what they left as security ($what), then mark it returned on the loan page.",
                '/loans/' . $loanId);
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
        $needed = self::coverNeeded(Money::fromDecimal($loan['amount']), Money::fromDecimal((string) ($loan['self_cover'] ?? '0')), $rules);
        $missingPeople = max(0, self::guarantorsNeeded($needed, $rules) - (int) $accepted['n']);
        $missingCover = max(0, $needed - Money::fromDecimal($accepted['total']));
        return $missingPeople === 0 && $missingCover === 0 ? null : ['guarantors' => $missingPeople, 'amount' => $missingCover];
    }

    /** Writes the schedule when the loan is disbursed */
    public static function createSchedule(PDO $db, array $loan, string $disbursedOn): void
    {
        $insert = $db->prepare('INSERT INTO loan_installments (loan_id, number, due_date, principal, interest, original_principal, original_interest) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (LoanMath::schedule(Money::fromDecimal($loan['amount']), $loan['interest_rate_monthly'], (int) $loan['months'], $disbursedOn) as $row) {
            $p = Money::toDecimal($row['principal']);
            $i = Money::toDecimal($row['interest']);
            $insert->execute([$loan['id'], $row['number'], $row['due_date'], $p, $i, $p, $i]);
        }
    }

    /**
     * A running loan over a new number of months: a fresh schedule from its payout date, at its own
     * rate, with every repayment already made applied again. Call inside a transaction.
     */
    public static function replan(PDO $db, array $loan, int $months): void
    {
        $db->prepare('DELETE FROM loan_installments WHERE loan_id = ?')->execute([$loan['id']]);
        $db->prepare('UPDATE loans SET months = ? WHERE id = ?')->execute([$months, $loan['id']]);
        $loan['months'] = $months;
        self::createSchedule($db, $loan, $loan['disbursed_on']);
        self::applyPayments($db, (int) $loan['id']);
    }

    /**
     * Works out, from scratch, every month of a loan from all repayments that stand (not reversed),
     * and records how each repayment was split. Repayments clear unpaid charges (penalties) first.
     *
     * Months before the new way starts (setting loan_rolling_from, 1 November 2026) keep the
     * schedule they were made with: each repayment clears the oldest unpaid instalment, interest
     * then principal. From that month on a loan is worked out month by month:
     *  - interest is charged on what is owed at the start of the month;
     *  - what the member pays in that calendar month goes to the interest, then the principal;
     *  - when the month is over, interest not paid is added to the principal, and what is owed is
     *    spread again over the months left, so the next month's payment is worked out afresh
     *    (more after paying less, less after paying more);
     *  - a month paid short stays "behind" until a later month is paid in full, so the office
     *    still sees who isn't paying.
     * Months not yet over are a projection: as if each is paid as demanded. The last month asks
     * for everything left; after it, what is unpaid is late as before. Closes the loan when all is
     * paid; reopens it if a reversal undoes that.
     */
    public static function applyPayments(PDO $db, int $loanId): void
    {
        $loan = $db->prepare('SELECT id, amount, interest_rate_monthly, user_id FROM loans WHERE id = ?');
        $loan->execute([$loanId]);
        $loan = $loan->fetch();
        $installments = $db->prepare('SELECT id, number, due_date, principal, interest, original_principal, original_interest FROM loan_installments WHERE loan_id = ? ORDER BY number');
        $installments->execute([$loanId]);
        $today = date('Y-m-d');
        $switch = \Sacco\Support\Settings::get('loan_rolling_from', '2026-11-01');
        $rolling = $today >= $switch;
        $rows = array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'due_date' => $r['due_date'],
            'month' => substr($r['due_date'], 0, 7),
            // Before the new way: the schedule as it was made
            'principal' => Money::fromDecimal($r['original_principal'] ?? $r['principal']),
            'interest' => Money::fromDecimal($r['original_interest'] ?? $r['interest']),
            'principal_paid' => 0,
            'interest_paid' => 0,
            'cleared_on' => null,
            'demanded' => null,
            'paid' => 0,
            'capitalised' => 0,
            'short' => 0,
            'balance_after' => null,
            'legacy' => !$rolling || substr($r['due_date'], 0, 7) < substr($switch, 0, 7),
        ], $installments->fetchAll());
        $hasRoll = $loan && $rows && in_array(false, array_column($rows, 'legacy'), true);

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

        $split = [];
        $toRoll = [];
        $lastPaidOn = null;
        foreach ($payments->fetchAll() as $payment) {
            $pid = (int) $payment['id'];
            $left = Money::fromDecimal($payment['amount']);
            $lastPaidOn = $payment['paid_on'];
            $split[$pid] = ['penalty' => 0, 'fee' => 0, 'interest' => 0, 'principal' => 0];
            foreach ($charges as &$charge) {
                // A repayment only clears charges that already existed when it was made
                if ($charge['charged_on'] > $payment['paid_on']) {
                    continue;
                }
                $take = min($left, $charge['amount'] - $charge['paid']);
                $charge['paid'] += $take;
                $split[$pid][$charge['type']] += $take;
                $left -= $take;
            }
            unset($charge);
            if ($hasRoll && $payment['paid_on'] >= $switch) {
                $toRoll[] = [$pid, $left, $payment['paid_on']];
                continue;
            }
            // The schedule as made: the oldest unpaid instalment, its interest then its principal
            foreach ($rows as &$row) {
                if ($hasRoll && !$row['legacy']) {
                    continue;
                }
                foreach (['interest', 'principal'] as $part) {
                    $take = min($left, $row[$part] - $row[$part . '_paid']);
                    $row[$part . '_paid'] += $take;
                    $split[$pid][$part] += $take;
                    $left -= $take;
                }
                if ($row['cleared_on'] === null && $row['principal_paid'] === $row['principal'] && $row['interest_paid'] === $row['interest']) {
                    $row['cleared_on'] = $payment['paid_on'];
                }
            }
            unset($row);
            // More than the old months needed: it counts in the first month of the new way
            if ($hasRoll && $left > 0) {
                $toRoll[] = [$pid, $left, $switch];
            }
        }
        foreach ($rows as &$row) {
            $row['demanded'] = $row['principal'] + $row['interest'];
            $row['paid'] = $row['principal_paid'] + $row['interest_paid'];
        }
        unset($row);

        if ($hasRoll) {
            $bp = (int) Money::parse((string) $loan['interest_rate_monthly']);
            // What is owed when the new way starts: the loan less principal repaid, with interest
            // left unpaid on the old months added to it
            $owed = Money::fromDecimal($loan['amount']);
            foreach ($rows as &$row) {
                if (!$row['legacy']) {
                    continue;
                }
                $owed -= $row['principal_paid'];
                if ($row['paid'] < $row['demanded']) {
                    $row['capitalised'] = $row['interest'] - $row['interest_paid'];
                    $row['short'] = $row['demanded'] - $row['paid'];
                    $owed += $row['capitalised'];
                    // Settled into what is owed: still "behind" (not cleared) until a month is paid in full
                    $row['interest_paid'] = $row['interest'];
                    $row['principal'] = $row['principal_paid'];
                }
                $row['balance_after'] = null;
            }
            unset($row);

            $roll = array_keys(array_filter($rows, fn ($r) => !$r['legacy']));
            $n = count($roll);
            $months = array_map(fn ($k) => $rows[$k]['month'], $roll);
            $bucket = array_fill(0, $n, []);
            foreach ($toRoll as $p) {
                $m = substr($p[2], 0, 7);
                $j = array_search($m, $months, true);
                $bucket[$j === false ? ($m < $months[0] ? 0 : $n - 1) : $j][] = $p;
            }
            $catchUp = function (int $upTo, string $on) use (&$rows) {
                foreach ($rows as $k => &$r) {
                    if ($k < $upTo && $r['cleared_on'] === null && $r['short'] > 0) {
                        $r['cleared_on'] = $on;
                    }
                }
            };
            foreach ($roll as $j => $k) {
                $row = &$rows[$k];
                $last = $j === $n - 1;
                $monthEnd = date('Y-m-t', strtotime($row['due_date']));
                $over = $monthEnd < $today;
                $interest = $owed > 0 ? (int) (round(intdiv($owed * $bp + 5000, 10000) / LoanMath::UNIT) * LoanMath::UNIT) : 0;
                $demanded = $last ? $owed + $interest : min($owed + $interest, LoanMath::installment($owed, $bp, $n - $j));
                $ip = $pp = 0;
                $completed = $lastDate = null;
                foreach ($bucket[$j] as [$pid, $cents, $on]) {
                    $a = min($cents, $interest - $ip);
                    $ip += $a;
                    $split[$pid]['interest'] += $a;
                    $cents -= $a;
                    $b = min($cents, $owed - $pp);
                    $pp += $b;
                    $split[$pid]['principal'] += $b;
                    $lastDate = $on;
                    if ($completed === null && $ip + $pp >= $demanded) {
                        $completed = $on;
                    }
                }
                $row['interest'] = $interest;
                $row['interest_paid'] = $ip;
                $row['principal_paid'] = $pp;
                $row['demanded'] = $demanded;
                $row['paid'] = $ip + $pp;
                $row['cleared_on'] = null;
                if ($over && !$last) {
                    // The month is over: unpaid interest joins the principal; the rest is spread again
                    $row['capitalised'] = $interest - $ip;
                    $row['interest_paid'] = $interest;
                    $row['principal'] = $pp;
                    $row['short'] = max(0, $demanded - $ip - $pp);
                    $owed = $owed - $pp + $row['capitalised'];
                    if ($row['short'] === 0) {
                        $row['cleared_on'] = $completed ?? $lastDate ?? $monthEnd;
                        $catchUp($k, $row['cleared_on']);
                    }
                } else {
                    // Not over yet (or the last month): what is asked; later months as if paid as asked
                    $row['principal'] = $last ? $owed : max($pp, $demanded - $interest);
                    $row['capitalised'] = 0;
                    $row['short'] = 0;
                    $owed -= $row['principal'];
                    if ($ip >= $interest && $pp >= $row['principal'] && ($ip + $pp) > 0) {
                        $row['cleared_on'] = $completed ?? $lastDate;
                        $catchUp($k, $row['cleared_on']);
                    }
                }
                $row['balance_after'] = $owed;
                unset($row);
            }
        }

        $allocate = $db->prepare('INSERT INTO loan_payment_allocations (payment_id, loan_id, part, amount) VALUES (?, ?, ?, ?)');
        foreach ($split as $pid => $parts) {
            foreach ($parts as $part => $cents) {
                if ($cents > 0) {
                    $allocate->execute([$pid, $loanId, $part, Money::toDecimal($cents)]);
                }
            }
        }

        $update = $db->prepare(
            'UPDATE loan_installments SET principal = ?, interest = ?, principal_paid = ?, interest_paid = ?, cleared_on = ?,
                    demanded = ?, paid_in_month = ?, capitalised = ?, short = ?, balance_after = ? WHERE id = ?'
        );
        $allPaid = true;
        foreach ($rows as $row) {
            $update->execute([
                Money::toDecimal($row['principal']), Money::toDecimal($row['interest']), Money::toDecimal($row['principal_paid']), Money::toDecimal($row['interest_paid']), $row['cleared_on'],
                Money::toDecimal($row['demanded']), Money::toDecimal($row['paid']), Money::toDecimal($row['capitalised']), Money::toDecimal($row['short']),
                $row['balance_after'] === null ? null : Money::toDecimal($row['balance_after']), $row['id'],
            ]);
            $allPaid = $allPaid && $row['principal_paid'] >= $row['principal'] && $row['interest_paid'] >= $row['interest'];
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

    /**
     * Every running loan worked out again: months end with the calendar, not only when someone
     * pays, so this runs once a day (with the reminders) before anything counts who is behind
     */
    public static function recalculateAll(PDO $db): int
    {
        $ids = $db->query("SELECT id FROM loans WHERE status = 'active' AND disbursed_on IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            Database::transaction(fn () => self::applyPayments($db, (int) $id));
        }
        return count($ids);
    }

    /** A loan instalment that is late: past its due date and unpaid, or a month paid short not yet caught up */
    public const LATE = '(i.cleared_on IS NULL AND (i.short > 0 OR i.principal + i.interest > i.principal_paid + i.interest_paid))';

    /** How much of a late instalment is late */
    public const LATE_AMOUNT = '(CASE WHEN i.short > 0 THEN i.short ELSE i.principal + i.interest - i.principal_paid - i.interest_paid END)';

    /** Totals for a disbursed loan, in cents. Unpaid charges (penalties) count as owed and overdue. */
    /** Whether a loan's processing fee comes from the borrower's savings (the SACCO's rule) */
    public static function feeFromSavings(): bool
    {
        return \Sacco\Support\Settings::get('loan_fee_from', 'savings') === 'savings';
    }

    public static function position(PDO $db, int $loanId): array
    {
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(principal), 0) AS principal, COALESCE(SUM(interest), 0) AS interest,
                    COALESCE(SUM(principal_paid), 0) AS principal_paid, COALESCE(SUM(interest_paid), 0) AS interest_paid,
                    COALESCE(SUM(capitalised), 0) AS capitalised
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
            'SELECT COALESCE(SUM(' . self::LATE_AMOUNT . '), 0)
             FROM loan_installments i WHERE i.loan_id = ? AND i.due_date < CURDATE() AND ' . self::LATE
        );
        $overdue->execute([$loanId]);
        $overdueInstalments = Money::fromDecimal($overdue->fetchColumn());

        return [
            'principal_left' => $t['principal'] - $t['principal_paid'],
            'interest_left' => $t['interest'] - $t['interest_paid'],
            'charges_left' => $chargesLeft,
            'total_left' => $t['principal'] + $t['interest'] - $t['principal_paid'] - $t['interest_paid'] + $chargesLeft,
            // Interest added to the principal counts as settled interest, but nobody paid it in money
            'paid' => $t['principal_paid'] + $t['interest_paid'] - $t['capitalised'],
            'interest_added' => $t['capitalised'],
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
