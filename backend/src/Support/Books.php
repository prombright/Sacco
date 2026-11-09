<?php

declare(strict_types=1);

namespace Sacco\Support;

use PDO;

/**
 * The SACCO's double-entry books.
 *
 * Every money record in the system becomes a balanced journal entry (debits = credits):
 *
 * - savings and shares: deposits and payroll (bank / member account), withdrawals (with the
 *   withdrawal fee to income), charges, adjustments (suspense), opening balances, dividends and
 *   profit shares (distributions), and transfers between accounts
 * - loans: payout (loans / bank and processing fee), interest as each instalment falls due
 *   (interest receivable / interest income), penalties, and repayments split into principal,
 *   interest and penalties; a repayment from savings comes out of the member's savings
 * - investments (at cost), their returns and the gain or loss when sold; income and expenses
 *
 * These "auto" entries are worked out from the records, which are themselves only ever added to
 * and reversed, and are rebuilt whenever the records change, so the books can never drift from
 * members' accounts. Journal entries typed in by the office are kept as they are.
 */
final class Books
{
    /** account system_key => id */
    private static ?array $keys = null;
    /** Entries being built: [date, narration, reference, source, source_id, lines] */
    private static array $entries = [];

    public static function account(PDO $db, string $key): int
    {
        if (self::$keys === null) {
            self::$keys = $db->query('SELECT system_key, id FROM gl_accounts WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        return (int) (self::$keys[$key] ?? throw new \RuntimeException("Account $key is missing from the chart of accounts"));
    }

    /** Brings the automatic entries up to date with the records, when anything has changed */
    public static function sync(PDO $db): void
    {
        $print = md5((string) $db->query(
            "SELECT CONCAT_WS('|',
                (SELECT COALESCE(MAX(id), 0) FROM transactions),
                (SELECT COALESCE(MAX(id), 0) FROM loan_payments),
                (SELECT COUNT(*) FROM loans WHERE disbursed_on IS NOT NULL),
                (SELECT COALESCE(MAX(updated_at), '') FROM loans),
                (SELECT COALESCE(SUM(interest), 0) FROM loan_installments),
                (SELECT COUNT(*) FROM loan_installments),
                (SELECT COALESCE(MAX(id), 0) FROM loan_charges),
                (SELECT COUNT(*) FROM loan_charges WHERE waived_at IS NOT NULL),
                (SELECT COALESCE(MAX(id), 0) FROM cashbook),
                (SELECT COALESCE(MAX(updated_at), '') FROM investments),
                (SELECT COUNT(*) FROM investments),
                (SELECT COALESCE(MAX(id), 0) FROM investment_returns),
                (SELECT COALESCE(MAX(id), 0) FROM transfers),
                (SELECT COUNT(*) FROM transfers WHERE status = 'reversed'),
                (SELECT CONCAT(COUNT(*), '-', COALESCE(SUM(status = 'paid' AND method = 'cash'), 0), '-', COALESCE(MAX(updated_at), '')) FROM membership_fees),
                CURDATE())"
        )->fetchColumn());
        $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'books_fingerprint'");
        $stmt->execute();
        if ($stmt->fetchColumn() === $print) {
            return;
        }
        $db->query("SELECT GET_LOCK('sacco_books', 20)");
        try {
            self::rebuild($db);
            $db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('books_fingerprint', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([$print]);
        } finally {
            $db->query("SELECT RELEASE_LOCK('sacco_books')");
        }
    }

    /** Works out every automatic entry again from the records. Returns how many there are. */
    public static function rebuild(PDO $db): int
    {
        self::$entries = [];
        self::$keys = null;
        self::savingsAndShares($db);
        self::transfers($db);
        self::loans($db);
        self::investments($db);
        self::cashbook($db);
        self::membershipFees($db);

        $inTransaction = $db->inTransaction();
        $inTransaction || $db->beginTransaction();
        try {
            $db->exec('DELETE FROM journal_entries WHERE auto = 1');
            $entry = $db->prepare('INSERT INTO journal_entries (entry_date, narration, reference, auto, source, source_id) VALUES (?, ?, ?, 1, ?, ?)');
            usort(self::$entries, fn ($a, $b) => [$a[0], $a[3], $a[4]] <=> [$b[0], $b[3], $b[4]]);
            foreach (array_chunk(self::$entries, 200) as $chunk) {
                $values = [];
                $args = [];
                foreach ($chunk as [$date, $narration, $reference, $source, $sourceId, $lines]) {
                    $entry->execute([$date, mb_substr($narration, 0, 255), $reference ? mb_substr($reference, 0, 80) : null, $source, $sourceId]);
                    $id = (int) $db->lastInsertId();
                    foreach ($lines as [$account, $cents, $member, $memo]) {
                        $values[] = '(?, ?, ?, ?, ?, ?)';
                        array_push($args, $id, $account, Money::toDecimal(max($cents, 0)), Money::toDecimal(max(-$cents, 0)), $member, $memo ? mb_substr($memo, 0, 255) : null);
                    }
                }
                if ($values) {
                    $db->prepare('INSERT INTO journal_lines (entry_id, account_id, debit, credit, member_id, memo) VALUES ' . implode(', ', $values))->execute($args);
                }
            }
            $inTransaction || $db->commit();
        } catch (\Throwable $e) {
            $inTransaction || $db->rollBack();
            throw $e;
        }
        $count = count(self::$entries);
        self::$entries = [];
        return $count;
    }

    // --- Building the entries ------------------------------------------------------------------

    /**
     * Adds an entry. Lines are [account id, cents (+ debit, - credit), member id, memo]; lines on the
     * same account and member are merged. Anything left unbalanced (it shouldn't be) goes to suspense.
     */
    private static function add(PDO $db, string $date, string $narration, string $source, ?int $sourceId, array $lines, ?string $reference = null): void
    {
        $merged = [];
        foreach ($lines as [$account, $cents, $member, $memo]) {
            if ($cents === 0) {
                continue;
            }
            $k = $account . ':' . ($member ?? '') . ':' . ($cents > 0 ? 'd' : 'c');
            if (isset($merged[$k])) {
                $merged[$k][1] += $cents;
            } else {
                $merged[$k] = [$account, $cents, $member, $memo];
            }
        }
        $off = array_sum(array_column($merged, 1));
        if ($off !== 0) {
            error_log("[sacco] books: $source $sourceId out of balance by $off cents; sent to suspense");
            $merged[] = [self::account($db, 'suspense'), -$off, null, 'Balancing difference'];
        }
        if ($merged) {
            self::$entries[] = [$date, $narration, $reference, $source, $sourceId, array_values($merged)];
        }
    }

    private static function memberAccount(PDO $db, string $account): int
    {
        return self::account($db, $account === 'shares' ? 'share_capital' : 'member_savings');
    }

    /** Savings and shares entries, grouped: a payroll upload or dividend run is one entry */
    private static function savingsAndShares(PDO $db): void
    {
        $fees = $db->query("SELECT transaction_id, fee, fee_on_top FROM withdrawal_requests WHERE transaction_id IS NOT NULL AND fee > 0")->fetchAll(PDO::FETCH_UNIQUE);
        $rows = $db->query(
            "SELECT t.*, o.type AS original_type, o.amount AS original_amount, o.id AS original_id, u.full_name
             FROM transactions t JOIN users u ON u.id = t.user_id LEFT JOIN transactions o ON o.id = t.reversal_of
             ORDER BY t.id"
        )->fetchAll();
        $groups = [];
        foreach ($rows as $t) {
            // Transfers are posted from the transfers themselves
            if ($t['type'] === 'transfer' || $t['original_type'] === 'transfer') {
                continue;
            }
            $reversal = $t['type'] === 'reversal';
            $type = $reversal ? $t['original_type'] : $t['type'];
            $cents = Money::fromDecimal($t['amount']);
            $memberAcct = self::memberAccount($db, $t['account']);
            $member = (int) $t['user_id'];
            $label = ucfirst(str_replace('_', ' ', $type));
            $lines = [[$memberAcct, -$cents, $member, $t['full_name'] . ' · ' . $t['account']]];
            $bank = (int) ($t['money_account_id'] ?: self::account($db, 'bank'));
            $other = match ($type) {
                'deposit', 'withdrawal' => 'bank',
                'charge' => 'withdrawal_fee_income',
                'membership_fee' => 'membership_fee_income',
                'adjustment' => 'suspense',
                'opening_balance' => 'opening',
                'dividend', 'profit' => 'distributions',
                default => 'suspense',
            };
            // A withdrawal whose fee came out of the amount: a smaller cheque, the rest is income
            $feeRow = $fees[$reversal ? $t['original_id'] : $t['id']] ?? null;
            if ($type === 'withdrawal' && $feeRow && !$feeRow['fee_on_top']) {
                $fee = Money::fromDecimal($feeRow['fee']) * ($cents < 0 ? 1 : -1);
                $lines[] = [$bank, $cents + $fee, null, null];
                $lines[] = [self::account($db, 'withdrawal_fee_income'), -$fee, $member, 'Withdrawal fee'];
            } else {
                $lines[] = [$other === 'bank' ? $bank : self::account($db, $other), $cents, $other === 'bank' ? null : $member, null];
            }

            if ($t['batch_id']) {
                $key = "payroll:{$t['batch_id']}:{$t['value_date']}:" . ($reversal ? 'r' : 'p');
                $narration = ($reversal ? 'Reversal of ' : '') . ($t['reference'] ?: 'Payroll');
                $source = 'payroll';
                $sourceId = (int) $t['batch_id'];
            } elseif ($t['dividend_run_id']) {
                $key = "dividend:{$t['dividend_run_id']}:{$t['value_date']}:" . ($reversal ? 'r' : 'p');
                $narration = ($reversal ? 'Reversal of ' : '') . ($t['reference'] ?: $label);
                $source = $type === 'profit' ? 'profit_share' : 'dividend';
                $sourceId = (int) $t['dividend_run_id'];
            } else {
                $key = 'tx:' . $t['id'];
                $narration = ($reversal ? 'Reversal: ' : '') . "$label, {$t['full_name']}" . ($t['narration'] ? ' · ' . $t['narration'] : '');
                $source = $reversal ? 'reversal' : $type;
                $sourceId = (int) $t['id'];
            }
            $groups[$key] ??= [$t['value_date'], $narration, $source, $sourceId, [], $t['reference']];
            array_push($groups[$key][4], ...$lines);
        }
        foreach ($groups as [$date, $narration, $source, $sourceId, $lines, $reference]) {
            self::add($db, $date, $narration, $source, $sourceId, $lines, $reference);
        }
    }

    private static function transfers(PDO $db): void
    {
        $rows = $db->query(
            "SELECT t.*, f.full_name AS from_name, r.full_name AS to_name FROM transfers t
             JOIN users f ON f.id = t.from_user_id JOIN users r ON r.id = t.to_user_id WHERE t.to_account <> 'loan'"
        )->fetchAll();
        foreach ($rows as $t) {
            $cents = Money::fromDecimal($t['amount']);
            $lines = [
                [self::memberAccount($db, $t['from_account']), $cents, (int) $t['from_user_id'], "{$t['from_name']} · {$t['from_account']}"],
                [self::memberAccount($db, $t['to_account']), -$cents, (int) $t['to_user_id'], "{$t['to_name']} · {$t['to_account']}"],
            ];
            $what = "Transfer {$t['transfer_no']}: {$t['from_name']}'s {$t['from_account']} to " . ($t['from_user_id'] === $t['to_user_id'] ? '' : "{$t['to_name']}'s ") . $t['to_account'];
            self::add($db, $t['value_date'], $what, 'transfer', (int) $t['id'], $lines, $t['transfer_no']);
            if ($t['status'] === 'reversed') {
                self::add($db, $t['reversed_on'], "Reversal of $what", 'transfer', (int) $t['id'], array_map(fn ($l) => [$l[0], -$l[1], $l[2], $l[3]], $lines), $t['transfer_no']);
            }
        }
    }

    private static function loans(PDO $db): void
    {
        $loans = $db->query(
            "SELECT l.*, u.full_name FROM loans l JOIN users u ON u.id = l.user_id
             WHERE l.disbursed_on IS NOT NULL AND l.status IN ('active', 'closed')"
        )->fetchAll(PDO::FETCH_UNIQUE);

        // Paid out: the loan is owed to the SACCO; the cheque leaves the bank less the processing fee
        foreach ($loans as $id => $l) {
            $amount = Money::fromDecimal($l['amount']);
            $fee = Money::fromDecimal($l['processing_fee']);
            $member = (int) $l['user_id'];
            $lines = [[self::account($db, 'loans'), $amount, $member, $l['loan_no']]];
            if ($l['source'] === 'old_records') {
                $lines[] = [self::account($db, 'opening'), -$amount, $member, 'Loan from the old records'];
            } else {
                $lines[] = [(int) ($l['money_account_id'] ?: self::account($db, 'bank')), -($amount - $fee), null, $l['disbursement_ref'] ? 'Cheque ' . $l['disbursement_ref'] : null];
                $lines[] = [self::account($db, 'loan_fee_income'), -$fee, $member, 'Processing fee'];
            }
            self::add($db, $l['disbursed_on'], "Loan {$l['loan_no']} paid out to {$l['full_name']}", 'loan_disbursement', (int) $id, $lines, $l['disbursement_ref']);
        }
        if (!$loans) {
            return;
        }
        $in = implode(',', array_map('intval', array_keys($loans)));

        // Interest is earned as each instalment falls due: one entry a month for all loans
        $due = $db->query(
            "SELECT loan_id, due_date, interest FROM loan_installments
             WHERE loan_id IN ($in) AND interest > 0 AND due_date <= CURDATE() ORDER BY due_date"
        )->fetchAll();
        $months = [];
        foreach ($due as $i) {
            $months[substr($i['due_date'], 0, 7)][] = $i;
        }
        foreach ($months as $month => $rows) {
            $lines = [];
            $total = 0;
            foreach ($rows as $i) {
                $cents = Money::fromDecimal($i['interest']);
                $l = $loans[$i['loan_id']];
                $lines[] = [self::account($db, 'interest_receivable'), $cents, (int) $l['user_id'], $l['loan_no']];
                $total += $cents;
            }
            $lines[] = [self::account($db, 'interest_income'), -$total, null, null];
            $date = min(date('Y-m-t', strtotime("$month-01")), date('Y-m-d'));
            self::add($db, $date, 'Loan interest for ' . date('F Y', strtotime("$month-01")), 'loan_interest', (int) str_replace('-', '', $month), $lines);
        }

        // Penalties charged (and taken off again when waived)
        $charges = $db->query("SELECT * FROM loan_charges WHERE loan_id IN ($in)")->fetchAll();
        foreach ($charges as $c) {
            $l = $loans[$c['loan_id']];
            $cents = Money::fromDecimal($c['amount']);
            $lines = [
                [self::account($db, 'penalty_receivable'), $cents, (int) $l['user_id'], $l['loan_no']],
                [self::account($db, 'penalty_income'), -$cents, null, null],
            ];
            self::add($db, $c['charged_on'], "Late-payment penalty on {$l['loan_no']}, {$l['full_name']}", 'loan_penalty', (int) $c['id'], $lines);
            if ($c['waived_at']) {
                self::add($db, substr($c['waived_at'], 0, 10), "Penalty waived on {$l['loan_no']}, {$l['full_name']}", 'loan_penalty', (int) $c['id'], array_map(fn ($x) => [$x[0], -$x[1], $x[2], $x[3]], $lines));
            }
        }

        // Repayments that stand, split as the loan's replay split them
        $parts = [];
        foreach ($db->query("SELECT payment_id, part, SUM(amount) AS amount FROM loan_payment_allocations WHERE loan_id IN ($in) GROUP BY payment_id, part") as $a) {
            $parts[$a['payment_id']][$a['part']] = Money::fromDecimal($a['amount']);
        }
        $transfers = $db->query("SELECT id, from_user_id, from_account FROM transfers")->fetchAll(PDO::FETCH_UNIQUE);
        $payments = $db->query(
            "SELECT p.* FROM loan_payments p WHERE p.loan_id IN ($in) AND p.reversal_of IS NULL
             AND NOT EXISTS (SELECT 1 FROM loan_payments r WHERE r.reversal_of = p.id) ORDER BY p.paid_on, p.id"
        )->fetchAll();
        $groups = [];
        foreach ($payments as $p) {
            $l = $loans[$p['loan_id']];
            $member = (int) $l['user_id'];
            $cents = Money::fromDecimal($p['amount']);
            $split = $parts[$p['id']] ?? [];
            $principal = $split['principal'] ?? 0;
            $interest = $split['interest'] ?? 0;
            $penalty = ($split['penalty'] ?? 0) + ($split['fee'] ?? 0);
            $over = $cents - $principal - $interest - $penalty;

            if ($p['transfer_id'] && isset($transfers[$p['transfer_id']])) {
                $t = $transfers[$p['transfer_id']];
                $from = [self::memberAccount($db, $t['from_account']), $cents, (int) $t['from_user_id'], 'Paid from ' . $t['from_account']];
            } elseif ($l['source'] === 'old_records' && $p['reference'] === 'Old records') {
                $from = [self::account($db, 'opening'), $cents, $member, 'Repaid before this system'];
            } else {
                $from = [(int) ($p['money_account_id'] ?: self::account($db, 'bank')), $cents, null, null];
            }
            $lines = [
                $from,
                [self::account($db, 'loans'), -$principal, $member, $l['loan_no']],
                [self::account($db, 'interest_receivable'), -$interest, $member, $l['loan_no']],
                [self::account($db, 'penalty_receivable'), -$penalty, $member, $l['loan_no']],
                [self::account($db, 'suspense'), -$over, $member, "Overpaid on {$l['loan_no']}"],
            ];
            if ($p['batch_id']) {
                $key = "batch:{$p['batch_id']}:{$p['paid_on']}";
                $groups[$key] ??= [$p['paid_on'], 'Loan repayments, ' . ($p['reference'] ?: 'payroll'), 'loan_repayment', (int) $p['batch_id'], [], $p['reference']];
                array_push($groups[$key][4], ...$lines);
            } else {
                self::add($db, $p['paid_on'], "Loan repayment on {$l['loan_no']}, {$l['full_name']}", 'loan_repayment', (int) $p['id'], $lines, $p['reference']);
            }
        }
        foreach ($groups as [$date, $narration, $source, $sourceId, $lines, $reference]) {
            self::add($db, $date, $narration, $source, $sourceId, $lines, $reference);
        }
    }

    private static function investments(PDO $db): void
    {
        foreach ($db->query('SELECT * FROM investments') as $i) {
            $cost = Money::fromDecimal($i['amount']);
            self::add($db, $i['started_on'], "Invested in {$i['name']}", 'investment', (int) $i['id'], [
                [self::account($db, 'investments'), $cost, null, $i['name']],
                [self::account($db, 'bank'), -$cost, null, null],
            ]);
            if ($i['status'] === 'closed' && $i['closed_on']) {
                $value = Money::fromDecimal($i['closing_value']);
                self::add($db, $i['closed_on'], "Sold / cashed in {$i['name']}", 'investment', (int) $i['id'], [
                    [self::account($db, 'bank'), $value, null, null],
                    [self::account($db, 'investments'), -$cost, null, $i['name']],
                    [self::account($db, 'investment_income'), $cost - $value, null, $value >= $cost ? 'Gain on sale' : 'Loss on sale'],
                ]);
            }
        }
        // Income received (a reversal is a negative row); gains on sale are posted with the sale above
        $rows = $db->query("SELECT r.*, i.name FROM investment_returns r JOIN investments i ON i.id = r.investment_id WHERE r.kind = 'income'")->fetchAll();
        foreach ($rows as $r) {
            $cents = Money::fromDecimal($r['amount']);
            self::add($db, $r['received_on'], ($r['reversal_of'] ? 'Reversal: return' : 'Return') . " on {$r['name']}", 'investment_return', (int) $r['id'], [
                [self::account($db, 'bank'), $cents, null, null],
                [self::account($db, 'investment_income'), -$cents, null, $r['note']],
            ]);
        }
    }

    private static function cashbook(PDO $db): void
    {
        foreach ($db->query('SELECT * FROM cashbook') as $c) {
            $cents = Money::fromDecimal($c['amount']);
            $account = (int) ($c['account_id'] ?: self::account($db, $c['kind'] === 'income' ? 'other_income' : 'other_expense'));
            $sign = $c['kind'] === 'income' ? 1 : -1;
            self::add($db, $c['entry_date'], ($c['kind'] === 'income' ? 'Receipt: ' : 'Payment: ') . ($c['description'] ?: $c['category']), $c['kind'] === 'income' ? 'receipt' : 'payment', (int) $c['id'], [
                [(int) ($c['money_account_id'] ?: self::account($db, 'bank')), $sign * $cents, null, null],
                [$account, -$sign * $cents, null, $c['description']],
            ], $c['reference']);
        }
    }

    /** Membership fees paid in cash (those taken from savings are posted with the savings entries) */
    private static function membershipFees(PDO $db): void
    {
        $rows = $db->query(
            "SELECT f.*, u.full_name FROM membership_fees f JOIN users u ON u.id = f.user_id WHERE f.status = 'paid' AND f.method = 'cash'"
        )->fetchAll();
        foreach ($rows as $f) {
            $cents = Money::fromDecimal($f['amount']);
            self::add($db, $f['paid_on'], "Membership fee {$f['year']}, {$f['full_name']}", 'membership_fee', (int) $f['id'], [
                [(int) ($f['money_account_id'] ?: self::account($db, 'bank')), $cents, null, null],
                [self::account($db, 'membership_fee_income'), -$cents, (int) $f['user_id'], (string) $f['year']],
            ], $f['reference']);
        }
    }

    // --- Reading the books ---------------------------------------------------------------------

    /** Each account's debits and credits up to a date (and from one, when given), in cents */
    /** $withClosing false leaves out year-end closing entries: for income and expense figures */
    public static function totals(PDO $db, string $to, ?string $from = null, bool $withClosing = true): array
    {
        $sql = 'SELECT l.account_id, SUM(l.debit) AS d, SUM(l.credit) AS c FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE e.entry_date <= ?'
            . ($withClosing ? '' : " AND e.source <> 'year_end'");
        $args = [$to];
        if ($from) {
            $sql .= ' AND e.entry_date >= ?';
            $args[] = $from;
        }
        $stmt = $db->prepare($sql . ' GROUP BY l.account_id');
        $stmt->execute($args);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['account_id']] = ['d' => Money::fromDecimal($r['d']), 'c' => Money::fromDecimal($r['c'])];
        }
        return $out;
    }

    /** Assets and expenses are normally debit balances; the rest credit */
    public static function debitNormal(string $type): bool
    {
        return in_array($type, ['asset', 'expense'], true);
    }

    /** An account's balance on its normal side, in cents */
    public static function balance(array $account, array $totals): int
    {
        $t = $totals[(int) $account['id']] ?? ['d' => 0, 'c' => 0];
        return self::debitNormal($account['type']) ? $t['d'] - $t['c'] : $t['c'] - $t['d'];
    }

    public static function chart(PDO $db): array
    {
        return $db->query("SELECT * FROM gl_accounts ORDER BY code")->fetchAll();
    }
}
