<?php

declare(strict_types=1);

namespace Sacco\Loans;

use PDO;
use Sacco\Support\Money;
use Sacco\Support\Settings;

/**
 * A member's full statement, as detailed as the old system's: every entry on their shares,
 * savings, savings interest, loans and loan interest in one list by date, with running savings
 * and loan balances. The old system's history (up to the day balances were brought in) runs on
 * into this system's entries; where the two don't agree, a line says by how much.
 *
 * Amounts are in cents, signed: in (+) and out (-) for savings and shares; issued or charged (+)
 * and repaid (-) for loans and loan interest; earned (+) and paid out (-) for savings interest.
 */
final class FullStatement
{
    private const LABELS = [
        'deposit' => 'Deposit', 'withdrawal' => 'Withdrawal', 'adjustment' => 'Adjustment', 'dividend' => 'Dividend', 'profit' => 'Profit share',
        'reversal' => 'Reversal', 'charge' => 'Withdrawal fee', 'transfer' => 'Transfer', 'membership_fee' => 'Membership fee',
        'subscription' => 'Annual subscription', 'loan_fee' => 'Loan processing fee', 'interest' => 'Savings interest paid into savings',
    ];

    /** @return array{entries: list<array>, cutoff: ?string} every entry, oldest first */
    public static function entries(PDO $db, int $userId): array
    {
        $cutoff = $db->query("SELECT MIN(value_date) FROM transactions WHERE type = 'opening_balance'")->fetchColumn() ?: null;
        $rows = [];
        $add = function (string $date, ?string $ref, string $details, array $amounts, string $source = 'new', int $order = 5) use (&$rows) {
            $rows[] = ['date' => $date, 'ref' => $ref, 'details' => $details, 'source' => $source, 'order' => $order] + array_merge(
                ['shares' => 0, 'savings' => 0, 'savings_interest' => 0, 'loan' => 0, 'loan_interest' => 0, 'other' => 0], $amounts);
        };
        $c = fn ($v) => Money::fromDecimal((string) $v);

        // The old system, line by line
        $old = ['savings' => 0, 'shares' => 0, 'savings_interest' => 0, 'loan' => 0];
        $s = $db->prepare('SELECT * FROM old_system_history WHERE user_id = ? ORDER BY entry_date, id');
        $s->execute([$userId]);
        foreach ($s->fetchAll() as $r) {
            $a = ['shares' => $c($r['shares']), 'savings' => $c($r['savings']), 'savings_interest' => $c($r['savings_interest']),
                'loan' => $c($r['loan']), 'loan_interest' => $c($r['loan_interest']), 'other' => $c($r['other'])];
            $old['savings'] += $a['savings'];
            $old['shares'] += $a['shares'];
            $old['savings_interest'] += $a['savings_interest'];
            $old['loan'] += $a['loan'] + $a['loan_interest'];
            $add($r['entry_date'], $r['voucher'], trim((string) $r['details']) ?: 'Old system entry', $a, 'old', 1);
        }

        // Where the balances brought in differ from the old history, the difference on its own line
        if ($cutoff) {
            $opening = $db->prepare("SELECT account, COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'opening_balance' GROUP BY account");
            $opening->execute([$userId]);
            $open = array_map($c, $opening->fetchAll(PDO::FETCH_KEY_PAIR));
            $payable = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM old_system_interest_payable WHERE user_id = ?');
            $payable->execute([$userId]);
            $loans = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM loans WHERE user_id = ? AND source = 'old_records' AND disbursed_on IS NOT NULL");
            $loans->execute([$userId]);
            $diff = [
                'savings' => ($open['savings'] ?? 0) - $old['savings'],
                'shares' => ($open['shares'] ?? 0) - $old['shares'],
                'savings_interest' => $c($payable->fetchColumn()) - $old['savings_interest'],
                'loan' => $c($loans->fetchColumn()) - $old['loan'],
            ];
            if (array_filter($diff)) {
                $add($cutoff, null, 'Difference between the old system\'s history and the balances brought into this system', $diff, 'new', 2);
            }
        }

        // This system: savings and shares (balances brought in are already in the old history)
        $s = $db->prepare(
            "SELECT t.*, o.type AS original_type FROM transactions t LEFT JOIN transactions o ON o.id = t.reversal_of
             WHERE t.user_id = ? AND t.type <> 'opening_balance' AND t.in_error = 0 ORDER BY t.value_date, t.id"
        );
        $s->execute([$userId]);
        foreach ($s->fetchAll() as $t) {
            $amt = $c($t['amount']);
            $a = [$t['account'] => $amt];
            $type = $t['type'] === 'reversal' ? $t['original_type'] : $t['type'];
            // Interest paid into savings leaves what is held; taking it back puts it back
            if ($type === 'interest') {
                $a['savings_interest'] = -$amt;
            }
            $label = $t['type'] === 'reversal' ? 'Reversal of a ' . strtolower(self::LABELS[$t['original_type']] ?? 'entry') : (self::LABELS[$t['type']] ?? ucfirst($t['type']));
            $details = trim($label . ($t['account'] === 'shares' ? ' (shares)' : '') . ($t['narration'] ? ' · ' . $t['narration'] : ''));
            $add($t['value_date'], $t['reference'], $details, $a);
        }

        // Savings interest earned each month, and paid out in cash
        $s = $db->prepare('SELECT month, balance, amount, rate_percent_month FROM savings_interest WHERE user_id = ? ORDER BY month');
        $s->execute([$userId]);
        foreach ($s->fetchAll() as $r) {
            $end = date('Y-m-t', strtotime($r['month'] . '-01'));
            $add($end, null, 'Interest on savings for ' . date('F Y', strtotime($end)) . ' (' . rtrim(rtrim((string) $r['rate_percent_month'], '0'), '.') . '% of ' . number_format((float) $r['balance']) . ')', ['savings_interest' => $c($r['amount'])], 'new', 7);
        }
        $s = $db->prepare("SELECT paid_on, amount, reference FROM savings_interest_payouts WHERE user_id = ? AND method = 'cash' ORDER BY paid_on, id");
        $s->execute([$userId]);
        foreach ($s->fetchAll() as $r) {
            $add($r['paid_on'], $r['reference'], 'Savings interest paid out in cash', ['savings_interest' => -$c($r['amount'])]);
        }

        // Loans: paid out, interest each month, interest added to the loan, penalties, repayments
        $s = $db->prepare('SELECT * FROM loans WHERE user_id = ? AND disbursed_on IS NOT NULL ORDER BY disbursed_on, id');
        $s->execute([$userId]);
        $today = date('Y-m-d');
        foreach ($s->fetchAll() as $l) {
            $no = $l['loan_no'];
            if ($l['source'] !== 'old_records') {
                $add($l['disbursed_on'], $l['disbursement_ref'], "Loan $no paid out ({$l['months']} months at {$l['interest_rate_monthly']}% a month)", ['loan' => $c($l['amount'])], 'new', 3);
            }
            $i = $db->prepare('SELECT due_date, interest, capitalised FROM loan_installments WHERE loan_id = ? ORDER BY number');
            $i->execute([$l['id']]);
            foreach ($i->fetchAll() as $r) {
                if ($r['due_date'] <= $today && $c($r['interest']) > 0) {
                    $add($r['due_date'], null, "Interest for " . date('F Y', strtotime($r['due_date'])) . " on $no", ['loan_interest' => $c($r['interest'])], 'new', 6);
                }
                $end = date('Y-m-t', strtotime($r['due_date']));
                if ($c($r['capitalised']) > 0 && $end < $today) {
                    $add($end, null, 'Interest for ' . date('F Y', strtotime($r['due_date'])) . " left unpaid, added to $no", ['loan' => $c($r['capitalised']), 'loan_interest' => -$c($r['capitalised'])], 'new', 8);
                }
            }
            $ch = $db->prepare('SELECT charged_on, amount, note FROM loan_charges WHERE loan_id = ? AND waived_at IS NULL ORDER BY charged_on, id');
            $ch->execute([$l['id']]);
            foreach ($ch->fetchAll() as $r) {
                $add($r['charged_on'], null, "Late-payment penalty on $no" . ($r['note'] ? ' · ' . $r['note'] : ''), ['loan_interest' => $c($r['amount'])]);
            }
            $p = $db->prepare(
                "SELECT p.id, p.paid_on, p.reference, p.narration,
                        COALESCE(SUM(CASE WHEN a.part = 'principal' THEN a.amount END), 0) AS principal,
                        COALESCE(SUM(CASE WHEN a.part <> 'principal' THEN a.amount END), 0) AS charges
                 FROM loan_payments p LEFT JOIN loan_payment_allocations a ON a.payment_id = p.id
                 WHERE p.loan_id = ? AND p.reversal_of IS NULL AND NOT EXISTS (SELECT 1 FROM loan_payments r WHERE r.reversal_of = p.id)
                 GROUP BY p.id, p.paid_on, p.reference, p.narration ORDER BY p.paid_on, p.id"
            );
            $p->execute([$l['id']]);
            foreach ($p->fetchAll() as $r) {
                // Old repayments brought in with the loan are in the old history already
                if ($l['source'] === 'old_records' && $r['reference'] === 'Old records') {
                    continue;
                }
                $add($r['paid_on'], $r['reference'], "Repayment on $no" . ($r['narration'] ? ' · ' . $r['narration'] : ''), ['loan' => -$c($r['principal']), 'loan_interest' => -$c($r['charges'])], 'new', 4);
            }
        }

        usort($rows, fn ($a, $b) => [$a['date'], $a['source'] === 'old' ? 0 : 1, $a['order']] <=> [$b['date'], $b['source'] === 'old' ? 0 : 1, $b['order']]);
        return ['entries' => $rows, 'cutoff' => $cutoff];
    }

    /** The statement for a period: brought forward, each entry with running balances, totals and a summary */
    public static function forPeriod(PDO $db, int $userId, ?string $from, string $to): array
    {
        ['entries' => $all, 'cutoff' => $cutoff] = self::entries($db, $userId);
        $run = ['savings' => 0, 'shares' => 0, 'loan' => 0, 'held' => 0];
        $opening = null;
        $rows = [];
        $sum = ['shares' => 0, 'savings' => 0, 'savings_interest' => 0, 'loan' => 0, 'loan_interest' => 0, 'other' => 0];
        $flows = ['loan_issued' => 0, 'loan_repaid' => 0, 'interest_charged' => 0, 'interest_paid' => 0, 'interest_earned' => 0, 'interest_paid_out' => 0, 'savings_in' => 0, 'savings_out' => 0];
        $hasOther = false;
        foreach ($all as $e) {
            if ($e['date'] > $to) {
                break;
            }
            if ($from !== null && $e['date'] < $from) {
                $run['savings'] += $e['savings'];
                $run['shares'] += $e['shares'];
                $run['loan'] += $e['loan'] + $e['loan_interest'];
                $run['held'] += $e['savings_interest'];
                continue;
            }
            $opening ??= $run;
            $run['savings'] += $e['savings'];
            $run['shares'] += $e['shares'];
            $run['loan'] += $e['loan'] + $e['loan_interest'];
            $run['held'] += $e['savings_interest'];
            foreach ($sum as $k => $_) {
                $sum[$k] += $e[$k];
            }
            $hasOther = $hasOther || $e['other'] !== 0;
            $flows['loan_issued'] += max(0, $e['loan']);
            $flows['loan_repaid'] += max(0, -$e['loan']);
            $flows['interest_charged'] += max(0, $e['loan_interest']);
            $flows['interest_paid'] += max(0, -$e['loan_interest']);
            $flows['interest_earned'] += max(0, $e['savings_interest']);
            $flows['interest_paid_out'] += max(0, -$e['savings_interest']);
            $flows['savings_in'] += max(0, $e['savings']);
            $flows['savings_out'] += max(0, -$e['savings']);
            $rows[] = [
                'date' => $e['date'], 'ref' => $e['ref'], 'details' => $e['details'] . ($e['source'] === 'old' ? '' : ''),
                'shares' => self::d($e['shares']), 'savings' => self::d($e['savings']), 'savings_balance' => Money::toDecimal($run['savings']),
                'savings_interest' => self::d($e['savings_interest']), 'loan' => self::d($e['loan']), 'loan_interest' => self::d($e['loan_interest']),
                'loan_balance' => Money::toDecimal($run['loan']), 'other' => self::d($e['other']),
                '_old' => $e['source'] === 'old',
            ];
        }
        $opening ??= $run;
        $price = (int) Money::parse(Settings::get('share_price', '0'));
        return [
            'from' => $from,
            'to' => $to,
            'cutoff' => $cutoff,
            'has_other' => $hasOther,
            'opening' => array_map([Money::class, 'toDecimal'], $opening),
            'closing' => array_map([Money::class, 'toDecimal'], $run) + ['share_count' => $price > 0 ? intdiv($run['shares'], $price) : null],
            'totals' => array_map([Money::class, 'toDecimal'], $sum),
            'flows' => array_map([Money::class, 'toDecimal'], $flows),
            'rows' => $rows,
        ];
    }

    /** A signed amount as a decimal, or null for nothing (an empty cell) */
    private static function d(int $cents): ?string
    {
        return $cents === 0 ? null : Money::toDecimal($cents);
    }
}
