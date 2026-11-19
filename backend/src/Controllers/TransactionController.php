<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Support\Approvals;
use Sacco\Loans\Credit;
use Sacco\Support\Periods;
use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Notify;
use Sacco\Support\Audit;
use Sacco\Support\Ledger;
use Sacco\Support\LedgerException;
use Sacco\Support\Money;
use Sacco\Support\Savings;
use Sacco\Support\Settings;

/**
 * Savings and shares: a member's own balances and statement, and admins recording entries.
 * An admin never records or reverses entries on their own account - another admin does that.
 */
final class TransactionController
{
    /** What an admin can record by hand, and which way it moves the balance (0 = they choose) */
    private const MANUAL_TYPES = [
        'deposit' => 1,
        'withdrawal' => -1,
        'adjustment' => 0,
        'opening_balance' => 1,
    ];

    /** GET /api/me/summary - the member's dashboard */
    public function mySummary(Request $request): never
    {
        $id = (int) Auth::id();
        $db = Database::tenant();

        // Loan figures across their active loans: what's owed and the soonest instalment
        $active = $db->prepare("SELECT id, loan_no, amount, disbursed_on FROM loans WHERE user_id = ? AND status = 'active' ORDER BY disbursed_on");
        $active->execute([$id]);
        $owed = 0;
        $next = null;
        $loans = [];
        foreach ($active->fetchAll() as $loan) {
            $p = Loans::position($db, (int) $loan['id']);
            $owed += $p['total_left'];
            if ($p['next_due_date'] && (!$next || $p['next_due_date'] < $next['date'])) {
                $next = ['date' => $p['next_due_date'], 'amount' => Money::toDecimal($p['next_due_amount'])];
            }
            $loans[] = [
                'id' => (int) $loan['id'],
                'loan_no' => $loan['loan_no'],
                'amount' => $loan['amount'],
                'disbursed_on' => $loan['disbursed_on'],
                'paid' => Money::toDecimal($p['paid']),
                'total_left' => Money::toDecimal($p['total_left']),
                'overdue' => Money::toDecimal($p['overdue']),
                'next_due_date' => $p['next_due_date'],
                'next_due_amount' => Money::toDecimal($p['next_due_amount']),
            ];
        }
        $savedThisYear = $db->prepare(
            "SELECT COALESCE(SUM(t.amount), 0) FROM transactions t WHERE t.user_id = ? AND t.type = 'deposit' AND t.value_date >= ? AND t.in_error = 0
             AND NOT EXISTS (SELECT 1 FROM transactions r WHERE r.reversal_of = t.id)"
        );
        $savedThisYear->execute([$id, date('Y-01-01')]);
        $fee = $db->prepare('SELECT year, amount, status, method, paid_on FROM membership_fees WHERE user_id = ? AND kind = \'subscription\' AND year = ?');
        $fee->execute([$id, (int) date('Y')]);
        $requests = $db->prepare("SELECT COUNT(*) FROM loan_guarantors g JOIN loans l ON l.id = g.loan_id WHERE g.guarantor_id = ? AND g.status = 'pending' AND l.status = 'pending_guarantors'");
        $requests->execute([$id]);

        // Dividends that still stand (not reversed): all of them, and the latest
        $dividends = $db->prepare(
            "SELECT t.amount, t.reference, t.value_date FROM transactions t
             WHERE t.user_id = ? AND t.type IN ('dividend', 'profit') AND NOT EXISTS (SELECT 1 FROM transactions r WHERE r.reversal_of = t.id)
             ORDER BY t.id DESC"
        );
        $dividends->execute([$id]);
        $dividends = $dividends->fetchAll();

        Response::ok([
            'dividends_total' => Money::toDecimal(array_sum(array_map(fn ($d) => Money::fromDecimal($d['amount']), $dividends))),
            'last_dividend' => $dividends[0] ?? null,
            'balances' => Ledger::balances($id),
            'pledged' => Money::toDecimal(Loans::pledged($db, $id)),
            // Savings not held for guarantees or withdrawals: what they can still pledge for a colleague
            'free_to_guarantee' => Money::toDecimal(Savings::free($db, $id)),
            'credit' => Credit::standing($db, $id),
            'share_price' => Settings::get('share_price', '0'),
            'share_position' => Savings::sharePosition($db, $id),
            // What a withdrawal request can ask for: savings only (shares never), leaving room for a fee charged on top
            'withdrawable' => Money::toDecimal(WithdrawalController::maxRequest(Savings::withdrawable($db, $id))),
            'loan_owed' => Money::toDecimal($owed),
            'next_repayment' => $next,
            'guarantee_requests' => (int) $requests->fetchColumn(),
            'recent' => Ledger::history($id, null, 1, 6, true)['transactions'],
            'loans' => $loans,
            // What the main loan type allows now: savings x its multiplier, less what is owed
            'loan_limit' => Money::toDecimal(Loans::eligibility($id, null, (int) (Loans::products($db)[0]['id'] ?? 0) ?: null)['limit']),
            'loan_wait_reason' => Loans::borrowerWaitReason($db, $id),
            'saved_this_year' => Money::toDecimal(Money::fromDecimal($savedThisYear->fetchColumn())),
            'membership_fee' => $fee->fetch() ?: null,
            'joining_fee' => (function () use ($db, $id) {
                $once = $db->prepare("SELECT amount, status FROM membership_fees WHERE user_id = ? AND kind = 'membership' ORDER BY id DESC LIMIT 1");
                $once->execute([$id]);
                return $once->fetch() ?: null;
            })(),
            'trend' => [
                'savings' => OfficeController::fundsTrend($db, $id, 'savings'),
                'shares' => OfficeController::fundsTrend($db, $id, 'shares'),
            ],
        ]);
    }

    /** GET /api/me/statement?from=&to= - printable statement of their own account */
    public function myStatement(Request $request): never
    {
        Response::ok($this->statement(Auth::user(), $request));
    }

    /** GET /api/members/{id}/statement?from=&to= (admin) */
    public function memberStatement(Request $request): never
    {
        Response::ok($this->statement($this->findUser((int) $request->param('id')), $request));
    }

    /**
     * Each account's balance at the start of the period, every entry in it (by the date the money
     * moved) with a running balance, and the balance at the end; plus their loans.
     */
    private function statement(array $user, Request $request): array
    {
        // no start date: the member's whole history, from their first entry (old system included)
        $from = $request->string('from');
        if ($from === '') {
            $first = Database::tenant()->prepare(
                "SELECT MIN(d) FROM (
                    SELECT MIN(entry_date) AS d FROM old_system_history WHERE user_id = ?
                    UNION ALL SELECT MIN(value_date) FROM transactions WHERE user_id = ? AND in_error = 0
                    UNION ALL SELECT MIN(disbursed_on) FROM loans WHERE user_id = ?
                 ) x"
            );
            $first->execute([(int) $user['id'], (int) $user['id'], (int) $user['id']]);
            $from = (string) ($first->fetchColumn() ?: date('Y-01-01'));
        }
        $to = $request->string('to') ?: date('Y-m-d');
        foreach ([$from, $to] as $d) {
            $p = \DateTime::createFromFormat('!Y-m-d', $d);
            if (!$p || $p->format('Y-m-d') !== $d) {
                Response::error('Enter valid dates.', 422);
            }
        }
        if ($from > $to) {
            Response::error('The start date is after the end date.', 422);
        }
        $db = Database::tenant();
        $id = (int) $user['id'];

        $accounts = [];
        foreach (Ledger::ACCOUNTS as $account) {
            $open = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND account = ? AND value_date < ? AND in_error = 0');
            $open->execute([$id, $account, $from]);
            $balance = Money::fromDecimal($open->fetchColumn());
            $opening = $balance;

            $stmt = $db->prepare(
                'SELECT value_date, type, amount, reference, narration FROM transactions
                 WHERE user_id = ? AND account = ? AND value_date BETWEEN ? AND ? AND in_error = 0 ORDER BY value_date, id'
            );
            $stmt->execute([$id, $account, $from, $to]);
            $in = 0;
            $out = 0;
            $rows = [];
            foreach ($stmt->fetchAll() as $t) {
                $cents = Money::fromDecimal($t['amount']);
                $balance += $cents;
                $cents > 0 ? $in += $cents : $out -= $cents;
                $rows[] = [
                    'date' => $t['value_date'],
                    'type' => $t['type'],
                    'details' => implode(' · ', array_filter([$t['reference'], $t['narration']])),
                    'amount' => Money::toDecimal($cents),
                    'balance' => Money::toDecimal($balance),
                ];
            }
            $accounts[$account] = [
                'opening' => Money::toDecimal($opening),
                'money_in' => Money::toDecimal($in),
                'money_out' => Money::toDecimal($out),
                'closing' => Money::toDecimal($balance),
                'rows' => $rows,
            ];
        }

        $loans = [];
        $stmt = $db->prepare("SELECT id, loan_no, amount, months, disbursed_on, status FROM loans WHERE user_id = ? AND status IN ('active', 'closed') ORDER BY id");
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $l) {
            $p = Loans::position($db, (int) $l['id']);
            $loans[] = [
                'loan_no' => $l['loan_no'],
                'amount' => $l['amount'],
                'months' => (int) $l['months'],
                'disbursed_on' => $l['disbursed_on'],
                'status' => $l['status'],
                'paid' => Money::toDecimal($p['paid']),
                'total_left' => Money::toDecimal($p['total_left']),
                'next_due_date' => $p['next_due_date'],
            ];
        }

        return [
            'member' => [
                'full_name' => $user['full_name'],
                'account_no' => $user['account_no'],
                'employee_no' => $user['employee_no'],
                'email' => $user['email'],
            ],
            'from' => $from,
            'to' => $to,
            'accounts' => $accounts,
            'loans' => $loans,
            'ledger' => $this->ledger($db, $id, $from, $to),
            'printed_at' => date('Y-m-d H:i'),
        ];
    }

    /**
     * The individual member statement as one ledger, the way the old system printed it: every
     * entry on savings, shares and loans in date order, with the savings running balance (Run Bal),
     * the loan interest still owed (Run Int.) and the loan plus interest still owed (Ln + Int.),
     * then the period's totals. The member's history from the old system comes first, entry by
     * entry; the opening balances it was brought in with are then left out, as they repeat it.
     * Savings interest from the old system is shown but, as there, not added to the balance (it is
     * payable until the committee settles it). Amounts are decimals; money out is negative.
     */
    private function ledger(\PDO $db, int $id, string $from, string $to): array
    {
        $price = (int) Money::parse(Settings::get('share_price', '0'));
        $q = function (string $sql, array $args) use ($db): array {
            $s = $db->prepare($sql);
            $s->execute($args);
            return $s->fetchAll();
        };
        $c = fn (?string $v) => Money::fromDecimal($v ?? '0');
        $hasOld = (bool) $q('SELECT 1 FROM old_system_history WHERE user_id = ? LIMIT 1', [$id]);

        // Every entry, from the beginning: [date, order, id, fields]
        $events = [];
        foreach ($q('SELECT id, entry_date, details, voucher, savings, shares, loan, loan_interest, savings_interest, other FROM old_system_history WHERE user_id = ?', [$id]) as $o) {
            $share = $c($o['shares']);
            $other = $c($o['other']);
            $events[] = [$o['entry_date'], 0, (int) $o['id'], [
                'vno' => $o['voucher'],
                'shares' => $share > 0 && $price > 0 ? intdiv($share, $price) : 0,
                'share' => $share,
                'savings' => $c($o['savings']),
                'interest' => $c($o['savings_interest']),
                'interest_in_balance' => false,
                'loan' => $c($o['loan']),
                'loan_int' => $c($o['loan_interest']),
                'details' => trim($o['details'] . ($other ? ' (other ' . number_format($other / 100) . ')' : '')),
            ]];
        }
        foreach ($q('SELECT id, account, type, amount, value_date, reference, narration FROM transactions WHERE user_id = ? AND in_error = 0', [$id]) as $t) {
            if ($hasOld && $t['type'] === 'opening_balance') {
                continue;
            }
            $v = $c($t['amount']);
            $isShares = $t['account'] === 'shares';
            $isInterest = !$isShares && $t['type'] === 'interest';
            $events[] = [$t['value_date'], 1, (int) $t['id'], [
                'vno' => $t['reference'],
                'shares' => $isShares && $price > 0 ? intdiv($v, $price) : 0,
                'share' => $isShares ? $v : 0,
                'savings' => !$isShares && !$isInterest ? $v : 0,
                'interest' => $isInterest ? $v : 0,
                'interest_in_balance' => true,
                'details' => $t['narration'] ?: ucfirst(str_replace('_', ' ', $t['type'])),
            ]];
        }
        foreach ($q("SELECT id, loan_no, amount, disbursed_on, disbursement_ref, source FROM loans WHERE user_id = ? AND disbursed_on IS NOT NULL AND status IN ('active', 'closed')", [$id]) as $l) {
            // a loan brought in from the old records is already in the old history
            if ($hasOld && $l['source'] === 'old_records') {
                continue;
            }
            $events[] = [$l['disbursed_on'], 2, (int) $l['id'], ['vno' => $l['loan_no'], 'loan' => $c($l['amount']), 'details' => trim('Loan disbursement ' . ($l['disbursement_ref'] ?? ''))]];
        }
        foreach ($q("SELECT i.id, i.due_date, i.interest, l.loan_no FROM loan_installments i JOIN loans l ON l.id = i.loan_id WHERE l.user_id = ? AND l.disbursed_on IS NOT NULL AND l.status IN ('active', 'closed') AND i.due_date > l.disbursed_on AND i.due_date <= ? AND i.interest > 0", [$id, $to]) as $i) {
            $events[] = [$i['due_date'], 3, (int) $i['id'], ['vno' => $i['loan_no'], 'loan_int' => $c($i['interest']), 'details' => 'Loan interest charged']];
        }
        foreach ($q('SELECT ch.id, ch.type, ch.amount, ch.charged_on, ch.note, l.loan_no FROM loan_charges ch JOIN loans l ON l.id = ch.loan_id WHERE l.user_id = ? AND ch.waived_at IS NULL', [$id]) as $ch) {
            $events[] = [$ch['charged_on'], 4, (int) $ch['id'], ['vno' => $ch['loan_no'], 'loan_int' => $c($ch['amount']), 'details' => $ch['note'] ?: ($ch['type'] === 'penalty' ? 'Late-payment penalty' : 'Loan fee')]];
        }
        foreach ($q(
            "SELECT p.id, p.paid_on, p.reference, p.narration, l.loan_no,
                    COALESCE(SUM(CASE WHEN a.part = 'principal' THEN a.amount END), 0) AS principal,
                    COALESCE(SUM(CASE WHEN a.part <> 'principal' THEN a.amount END), 0) AS other
             FROM loan_payments p JOIN loans l ON l.id = p.loan_id LEFT JOIN loan_payment_allocations a ON a.payment_id = p.id
             WHERE l.user_id = ? GROUP BY p.id, p.paid_on, p.reference, p.narration, l.loan_no",
            [$id],
        ) as $p) {
            $events[] = [$p['paid_on'], 5, (int) $p['id'], ['vno' => $p['reference'] ?: $p['loan_no'], 'loan' => -$c($p['principal']), 'loan_int' => -$c($p['other']), 'details' => $p['narration'] ?: 'Loan repayment']];
        }
        usort($events, fn ($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        // Run through everything: the figures before the period make the opening line
        $savings = 0;
        $principal = 0;
        $interest = 0;
        $opening = null;
        $rows = [];
        $totals = ['shares' => 0, 'share' => 0, 'savings' => 0, 'interest' => 0, 'loan_issue' => 0, 'loan_paid' => 0, 'interest_charge' => 0, 'interest_paid' => 0];
        // reads the running figures as they are now (a reference, not a copy)
        $shareTotal = 0;
        $snapshot = function () use (&$savings, &$principal, &$interest, &$shareTotal, $price): array {
            return [
                'savings' => Money::toDecimal($savings), 'loan' => Money::toDecimal($principal), 'loan_interest' => Money::toDecimal($interest),
                // share capital held, and how many shares that is
                'shares' => Money::toDecimal($shareTotal), 'share_count' => $price > 0 ? intdiv($shareTotal, $price) : null,
            ];
        };
        foreach ($events as [$date, , , $e]) {
            if ($date > $to) {
                break;
            }
            if ($date >= $from && $opening === null) {
                $opening = $snapshot();
            }
            $e += ['shares' => 0, 'share' => 0, 'savings' => 0, 'interest' => 0, 'interest_in_balance' => true, 'loan' => 0, 'loan_int' => 0];
            $savings += $e['savings'] + ($e['interest_in_balance'] ? $e['interest'] : 0);
            $principal += $e['loan'];
            $interest += $e['loan_int'];
            $shareTotal += $e['share'];
            if ($date < $from) {
                continue;
            }
            $totals['shares'] += $e['shares'];
            $totals['share'] += $e['share'];
            $totals['savings'] += $e['savings'];
            $totals['interest'] += $e['interest'];
            $e['loan'] > 0 ? $totals['loan_issue'] += $e['loan'] : $totals['loan_paid'] -= $e['loan'];
            $e['loan_int'] > 0 ? $totals['interest_charge'] += $e['loan_int'] : $totals['interest_paid'] -= $e['loan_int'];
            $rows[] = [
                'date' => $date,
                'vno' => $e['vno'],
                'shares' => $e['shares'],
                'share' => Money::toDecimal($e['share']),
                'savings' => Money::toDecimal($e['savings']),
                'run_bal' => Money::toDecimal($savings),
                'interest' => Money::toDecimal($e['interest']),
                'loan' => Money::toDecimal($e['loan']),
                'loan_int' => Money::toDecimal($e['loan_int']),
                'run_int' => Money::toDecimal($interest),
                'ln_int' => Money::toDecimal($principal + $interest),
                'details' => $e['details'],
            ];
        }
        $opening ??= $snapshot();
        foreach (['share', 'savings', 'interest', 'loan_issue', 'loan_paid', 'interest_charge', 'interest_paid'] as $k) {
            $totals[$k] = Money::toDecimal($totals[$k]);
        }
        $first = $events[0][0] ?? null;

        return ['opening' => $opening, 'rows' => $rows, 'totals' => $totals, 'closing' => $snapshot(), 'first_date' => $first];
    }

    /** GET /api/me/transactions?account=&page= */
    public function myTransactions(Request $request): never
    {
        Response::ok(Ledger::history((int) Auth::id(), $this->account($request), $this->page($request), 50, true));
    }

    /** GET /api/members/{id} - a member with their balances (admin) */
    public function member(Request $request): never
    {
        $user = $this->findUser((int) $request->param('id'));
        $db = Database::tenant();
        $id = (int) $user['id'];

        // Their loans, newest first, with what's left on the running ones
        $rows = $db->prepare('SELECT id, loan_no, amount, months, status, disbursed_on, created_at, closed_on FROM loans WHERE user_id = ? ORDER BY id DESC');
        $rows->execute([$id]);
        $loans = [];
        $owed = 0;
        $overdue = 0;
        foreach ($rows->fetchAll() as $l) {
            $left = null;
            if ($l['status'] === 'active') {
                $p = Loans::position($db, (int) $l['id']);
                $owed += $p['total_left'];
                $overdue += $p['overdue'];
                $left = ['total_left' => Money::toDecimal($p['total_left']), 'overdue' => Money::toDecimal($p['overdue']), 'next_due_date' => $p['next_due_date']];
            }
            $loans[] = ['id' => (int) $l['id'], 'loan_no' => $l['loan_no'], 'amount' => $l['amount'], 'months' => (int) $l['months'], 'status' => $l['status'],
                'disbursed_on' => $l['disbursed_on'], 'applied_on' => substr((string) $l['created_at'], 0, 10), 'closed_on' => $l['closed_on']] + ($left ?? []);
        }
        // Colleagues' loans they stand behind
        $g = $db->prepare(
            "SELECT g.id AS gid, l.id, l.loan_no, l.status, g.amount, u.full_name AS borrower_name FROM loan_guarantors g
             JOIN loans l ON l.id = g.loan_id JOIN users u ON u.id = l.user_id
             WHERE g.guarantor_id = ? AND g.status = 'accepted' AND l.status IN ('pending_guarantors', 'pending_approval', 'approved', 'active')
             ORDER BY l.id DESC"
        );
        $g->execute([$id]);
        // What each guarantee holds of their savings now (less as the loan is repaid)
        $pledges = Loans::pledges($db, $id);
        $guarantees = array_map(function ($row) use ($pledges) {
            $row['held'] = Money::toDecimal($pledges[(int) $row['gid']]['held'] ?? Money::fromDecimal($row['amount']));
            unset($row['gid']);
            return $row;
        }, $g->fetchAll());

        // How much they can borrow, loan type by loan type, under the SACCO's rules today
        $free = Savings::free($db, $id);
        $borrowing = [];
        foreach (Loans::products($db) as $product) {
            $rules = Loans::productRules($db, (int) $product['id']);
            $e = Loans::eligibility($id, null, (int) $product['id']);
            $borrowing[] = [
                'product' => $product['name'],
                'multiplier' => rtrim(rtrim((string) $rules['savings_multiplier'], '0'), '.'),
                'limit' => Money::toDecimal($e['limit']),
                'limit_percent' => $e['limit_percent'],
                // Why they can't apply for it now (too new a member, out of season, one open already)
                'blocked' => Loans::productBlock($db, $id, $rules),
            ];
        }

        Response::ok([
            'borrowing' => [
                'products' => $borrowing,
                'savings' => Money::toDecimal(Ledger::balance($db, $id, 'savings')),
                'owed' => Money::toDecimal(Loans::owedOnOpenLoans($db, $id)),
                // A loan up to their free savings needs no guarantor (the committee's rule)
                'without_guarantor' => Money::toDecimal($free),
                'free_to_guarantee' => Money::toDecimal($free),
                'held_for_own_loans' => Money::toDecimal(Loans::heldForOwnLoans($db, $id)),
                'guaranteed_total' => Money::toDecimal(array_sum(array_map(fn ($p) => $p['pledge'], $pledges))),
                'guarantee_held' => Money::toDecimal(array_sum(array_map(fn ($p) => $p['held'], $pledges))),
            ],
            'user' => AuthController::present($user),
            'loans' => $loans,
            'loan_owed' => Money::toDecimal($owed),
            'loan_overdue' => Money::toDecimal($overdue),
            'guarantees' => $guarantees,
            'balances' => Ledger::balances((int) $user['id']),
            'share_price' => Settings::get('share_price', '0'),
            'pledged' => Money::toDecimal(Loans::pledged(Database::tenant(), (int) $user['id'])),
            'credit' => Credit::standing(Database::tenant(), (int) $user['id']),
        ]);
    }

    /** GET /api/members/{id}/transactions?account=&page= (admin) */
    public function memberTransactions(Request $request): never
    {
        $user = $this->findUser((int) $request->param('id'));
        Response::ok(Ledger::history((int) $user['id'], $this->account($request), $this->page($request)));
    }

    /**
     * POST /api/members/{id}/transactions (admin)
     * { account, type, direction? (in|out, adjustments only), amount, value_date, reference?, narration? }
     */
    public function store(Request $request): never
    {
        $user = $this->findUser((int) $request->param('id'));
        if ($user['role'] !== 'member') {
            Response::error('This is an office account; it has no savings or shares. Record it on the person\'s member account.', 422);
        }
        if (Auth::isMe((int) $user['id'])) {
            Response::error('You can\'t record entries on your own account. Ask another admin.', 403);
        }

        $errors = [];
        $account = $request->string('account');
        if (!in_array($account, Ledger::ACCOUNTS, true)) {
            $errors['account'] = 'Choose savings or shares.';
        }
        $type = $request->string('type');
        if (!array_key_exists($type, self::MANUAL_TYPES)) {
            $errors['type'] = 'Choose what kind of entry this is.';
        }
        $sign = self::MANUAL_TYPES[$type] ?? 1;
        if ($sign === 0) {
            $direction = $request->string('direction');
            if (!in_array($direction, ['in', 'out'], true)) {
                $errors['direction'] = 'Choose whether the adjustment adds or takes away.';
            }
            $sign = $direction === 'out' ? -1 : 1;
        }
        $cents = Money::parse($request->input('amount'));
        if ($cents === null || $cents <= 0) {
            $errors['amount'] = 'Enter an amount greater than zero.';
        }
        $valueDate = $request->string('value_date') ?: date('Y-m-d');
        $parsed = \DateTime::createFromFormat('!Y-m-d', $valueDate);
        if (!$parsed || $parsed->format('Y-m-d') !== $valueDate) {
            $errors['value_date'] = 'Enter a valid date.';
        } elseif ($valueDate > date('Y-m-d')) {
            $errors['value_date'] = 'The date can\'t be in the future.';
        }
        $narration = mb_substr($request->string('narration'), 0, 255);
        if ($type === 'adjustment' && $narration === '') {
            $errors['narration'] = 'Say why this adjustment is being made.';
        }
        if ($errors) {
            Response::error(reset($errors), 422, $errors);
        }
        Approvals::gate($request, [self::class, 'store'], 'entry', 'money', $cents,
            ucfirst(str_replace('_', ' ', $type)) . ' of ' . Notify::cents($cents) . ($sign < 0 ? ' from ' : ' to ') . $user['full_name'] . "'s $account" . ($narration !== '' ? " ($narration)" : ''),
            (int) $user['id']);

        $moneyAccount = in_array($type, ['deposit', 'withdrawal'], true) ? Periods::moneyAccount($request) : null;
        try {
            $row = Database::transaction(function (\PDO $db) use ($user, $account, $type, $sign, $cents, $valueDate, $narration, $request, $moneyAccount) {
                if ($type === 'opening_balance') {
                    $exists = $db->prepare('SELECT 1 FROM transactions WHERE user_id = ? AND account = ? LIMIT 1');
                    $exists->execute([$user['id'], $account]);
                    if ($exists->fetchColumn()) {
                        throw new LedgerException('This account already has entries, so it can\'t get an opening balance. Use an adjustment instead.');
                    }
                }
                // Savings pledged for someone's loan stay put until that loan is repaid
                if ($account === 'savings' && $sign < 0) {
                    $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$user['id']]);
                    $room = Savings::withdrawable($db, (int) $user['id']);
                    if ($cents > $room) {
                        throw new LedgerException('Only ' . number_format($room / 100) . ' can come off these savings: '
                            . number_format(Savings::minBalance() / 100) . ' must always stay, and any amount pledged as a guarantee or held for a withdrawal stays too.');
                    }
                }
                // Members keep the minimum shares; only leaving the SACCO pays them out
                if ($account === 'shares' && $sign < 0 && ($min = Savings::minShareCapital()) > 0) {
                    $after = Ledger::balance($db, (int) $user['id'], 'shares') - $cents;
                    if ($after < $min) {
                        throw new LedgerException('Members must keep at least ' . Settings::get('min_shares') . ' shares (' . number_format($min / 100)
                            . ') in share capital. Shares are only paid out when someone leaves the SACCO.');
                    }
                }
                return Ledger::post($db, (int) $user['id'], $account, $type, $sign * $cents, [
                    'value_date' => $valueDate,
                    'money_account_id' => $moneyAccount,
                    'reference' => mb_substr($request->string('reference'), 0, 80) ?: null,
                    'narration' => $narration ?: null,
                ]);
            });
        } catch (LedgerException $e) {
            Response::error($e->getMessage(), 422, ['amount' => $e->getMessage()]);
        }
        Audit::log('transaction.recorded', 'transaction', (int) $row['id'], [
            'member' => (int) $user['id'], 'account' => $account, 'type' => $type, 'amount' => $row['amount'],
        ]);
        $balances = Ledger::balances((int) $user['id']);
        $what = match ($type) {
            'deposit' => "added to your $account",
            'withdrawal' => "taken from your $account",
            'opening_balance' => "recorded as your opening $account balance",
            default => ($sign > 0 ? 'added to' : 'taken from') . " your $account (an adjustment)",
        };
        Notify::member((int) $user['id'], $sign > 0 ? 'money_in' : 'money_out', Notify::cents($cents) . " $what",
            'Your ' . $account . ' balance is now ' . Notify::money($balances[$account]) . '. Dated ' . date('j M Y', strtotime($valueDate)) . '.'
            . ($narration !== '' ? " Note: $narration" : ''), '/statement');

        Response::ok(['transaction' => Ledger::present($row), 'balances' => Ledger::balances((int) $user['id'])], 201);
    }

    /** GET /api/transactions?from=&to=&type=&account=&q=&page= (admin) - every member's entries */
    public function all(Request $request): never
    {
        $from = $request->string('from') ?: date('Y-m-01');
        $to = $request->string('to') ?: date('Y-m-d');
        $where = 't.value_date BETWEEN ? AND ?';
        $args = [$from, $to];
        if (($type = $request->string('type')) !== '') {
            $where .= ' AND t.type = ?';
            $args[] = $type;
        }
        if (($account = $this->account($request)) !== null) {
            $where .= ' AND t.account = ?';
            $args[] = $account;
        }
        if (($q = $request->string('q')) !== '') {
            $where .= ' AND (u.full_name LIKE ? OR u.account_no LIKE ? OR u.employee_no LIKE ? OR t.reference LIKE ? OR t.narration LIKE ?)';
            array_push($args, ...array_fill(0, 5, "%$q%"));
        }
        $page = $this->page($request);
        $per = 50;
        $db = Database::tenant();
        $count = $db->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount END), 0), COALESCE(SUM(CASE WHEN t.amount < 0 THEN -t.amount END), 0)
            FROM transactions t JOIN users u ON u.id = t.user_id WHERE $where");
        $count->execute($args);
        [$total, $in, $out] = $count->fetch(\PDO::FETCH_NUM);
        $stmt = $db->prepare(
            "SELECT t.*, u.full_name, u.account_no, CASE WHEN c.role = 'super_admin' AND c.office_via_member = 0 THEN 'System administrator' ELSE c.full_name END AS created_by_name, r.id AS reversed_by
             FROM transactions t JOIN users u ON u.id = t.user_id LEFT JOIN users c ON c.id = t.created_by
             LEFT JOIN transactions r ON r.reversal_of = t.id
             WHERE $where ORDER BY t.value_date DESC, t.id DESC LIMIT $per OFFSET " . (($page - 1) * $per)
        );
        $stmt->execute($args);
        Response::ok(['transactions' => $stmt->fetchAll(), 'total' => (int) $total, 'money_in' => $in, 'money_out' => $out, 'page' => $page, 'per_page' => $per]);
    }

    /** POST /api/transactions/{id}/reverse { reason } (admin) */
    public function reverse(Request $request): never
    {
        $reason = mb_substr($request->string('reason'), 0, 255);
        if ($reason === '') {
            Response::error('Say why this entry is being reversed.', 422, ['reason' => 'Required']);
        }
        $original = Ledger::find(Database::tenant(), (int) $request->param('id'))
            ?? Response::error('That transaction doesn\'t exist.', 404);
        if (Auth::isMe((int) $original['user_id'])) {
            Response::error('You can\'t reverse entries on your own account. Ask another admin.', 403);
        }
        if ($original['type'] === 'transfer') {
            Response::error('This is part of a transfer. Reverse the whole transfer on the Internal transfers page.', 422);
        }

        // Entered in error (e.g. on the wrong member): the entry and its correction are hidden from the member
        $inError = (bool) $request->input('entered_in_error', false);
        $who = Database::tenant()->prepare('SELECT full_name FROM users WHERE id = ?');
        $who->execute([$original['user_id']]);
        Approvals::gate($request, [self::class, 'reverse'], 'reversal', 'money', abs(Money::fromDecimal($original['amount'])),
            'Reverse ' . str_replace('_', ' ', $original['type']) . ' of ' . Notify::money(ltrim($original['amount'], '-')) . ' on ' . $who->fetchColumn() . "'s {$original['account']}" . ($inError ? ' (entered in error)' : '') . ": $reason",
            (int) $original['user_id']);
        try {
            $row = Database::transaction(function (\PDO $db) use ($original, $reason, $inError) {
                $row = Ledger::reverse($db, (int) $original['id'], $reason);
                // Savings interest paid into savings and taken back: it is held for the member again
                if ($original['type'] === 'interest') {
                    $db->prepare('DELETE FROM savings_interest_payouts WHERE transaction_id = ?')->execute([$original['id']]);
                }
                if ($inError) {
                    $db->prepare('UPDATE transactions SET in_error = 1 WHERE id IN (?, ?)')->execute([$original['id'], $row['id']]);
                    $row['in_error'] = 1;
                }
                return $row;
            });
        } catch (LedgerException $e) {
            Response::error($e->getMessage(), 422);
        }
        Audit::log($inError ? 'transaction.entered_in_error' : 'transaction.reversed', 'transaction', (int) $original['id'], ['member' => (int) $original['user_id'], 'reversal' => (int) $row['id'], 'reason' => $reason]);
        $account = $original['account'];
        $balance = Notify::money(Ledger::balances((int) $original['user_id'])[$account]);
        if ($inError) {
            Notify::member((int) $original['user_id'], 'money_reversed', 'A mistaken entry was removed',
                'An entry of ' . Notify::money(ltrim($original['amount'], '-')) . ' recorded on your ' . $account . ' by mistake on ' . date('j M Y', strtotime($original['created_at'] ?? $original['value_date']))
                . " has been removed. Nothing was taken from your money. Your $account balance is $balance.", '/statement');
        } else {
            Notify::member((int) $original['user_id'], 'money_reversed', "An entry on your $account was reversed",
                'The ' . str_replace('_', ' ', $original['type']) . ' of ' . Notify::money(ltrim($original['amount'], '-')) . ' dated ' . date('j M Y', strtotime($original['value_date']))
                . " was reversed: $reason. Your $account balance is now $balance.", '/statement');
        }

        Response::ok(['transaction' => Ledger::present($row), 'balances' => Ledger::balances((int) $original['user_id'])], 201);
    }

    /**
     * PATCH /api/transactions/{id} { amount, value_date, reference?, narration?, reason } (super admin)
     * - corrects a standalone entry recorded by mistake (a deposit, withdrawal, adjustment or opening
     * balance); every later balance is worked out again. The member is told.
     */
    public function edit(Request $request): never
    {
        $db = Database::tenant();
        $t = Ledger::find($db, (int) $request->param('id')) ?? Response::error('That transaction doesn\'t exist.', 404);
        if (Auth::isMe((int) $t['user_id'])) {
            Response::error('You can\'t change entries on your own account. Ask another super admin.', 403);
        }
        if ($why = Ledger::fixable($t)) {
            Response::error($why, 422);
        }
        $reason = mb_substr($request->string('reason'), 0, 255);
        if ($reason === '') {
            Response::error('Say what was wrong with it: the member sees this.', 422, ['reason' => 'Required']);
        }
        $cents = Money::parse($request->string('amount'));
        if ($cents === null || $cents <= 0) {
            Response::error('Enter the right amount.', 422, ['amount' => 'Required']);
        }
        $date = $request->string('value_date') ?: $t['value_date'];
        $d = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            Response::error('Enter a valid date, not in the future.', 422, ['value_date' => 'Invalid']);
        }
        // Money out stays money out: the amount keeps the entry's direction
        $signed = Money::fromDecimal((string) $t['amount']) < 0 ? -$cents : $cents;
        $reference = mb_substr($request->input('reference') !== null ? $request->string('reference') : (string) $t['reference'], 0, 80) ?: null;
        $narration = mb_substr($request->input('narration') !== null ? $request->string('narration') : (string) $t['narration'], 0, 255) ?: null;
        $changes = [];
        foreach (['amount' => [$t['amount'], Money::toDecimal($signed)], 'value_date' => [$t['value_date'], $date], 'reference' => [$t['reference'], $reference], 'narration' => [$t['narration'], $narration]] as $k => [$from, $to]) {
            if ((string) $from !== (string) $to) {
                $changes[$k] = ['from' => $from, 'to' => $to];
            }
        }
        if (!$changes) {
            Response::error('Nothing was changed.', 422);
        }
        Periods::check($t['value_date']);
        Periods::check($date);
        $who = $db->prepare('SELECT full_name FROM users WHERE id = ?');
        $who->execute([$t['user_id']]);
        $name = (string) $who->fetchColumn();
        Approvals::gate($request, [self::class, 'edit'], 'correction', 'money', max(abs(Money::fromDecimal((string) $t['amount'])), $cents),
            'Correct a ' . str_replace('_', ' ', $t['type']) . ' of ' . Notify::money(ltrim($t['amount'], '-')) . " on $name's {$t['account']} to " . Notify::cents($cents) . ": $reason",
            (int) $t['user_id']);
        try {
            Database::transaction(function (\PDO $db) use ($t, $signed, $date, $reference, $narration) {
                $db->prepare('UPDATE transactions SET amount = ?, value_date = ?, reference = ?, narration = ? WHERE id = ?')
                    ->execute([Money::toDecimal($signed), $date, $reference, $narration, $t['id']]);
                Ledger::rebalance($db, (int) $t['user_id'], $t['account']);
            });
        } catch (LedgerException $e) {
            Response::error($e->getMessage(), 422);
        }
        Audit::log('transaction.edited', 'transaction', (int) $t['id'], ['member' => (int) $t['user_id'], 'changes' => $changes, 'reason' => $reason]);
        $balance = Notify::money(Ledger::balances((int) $t['user_id'])[$t['account']]);
        $what = str_replace('_', ' ', $t['type']);
        $detail = isset($changes['amount'])
            ? 'was ' . Notify::money(ltrim($t['amount'], '-')) . ' and is now ' . Notify::cents($cents)
            : 'was corrected';
        if (isset($changes['value_date'])) {
            $detail .= ', dated ' . date('j M Y', strtotime($date));
        }
        Notify::member((int) $t['user_id'], 'money_corrected', "An entry on your {$t['account']} was corrected",
            "The $what dated " . date('j M Y', strtotime($t['value_date'])) . " $detail: $reason. Your {$t['account']} balance is now $balance.", '/statement');
        Response::ok(['transaction' => Ledger::present(Ledger::find($db, (int) $t['id'])), 'balances' => Ledger::balances((int) $t['user_id'])]);
    }

    /**
     * DELETE /api/transactions/{id} { reason } (super admin) - removes a standalone entry recorded by
     * mistake, as if it had never been made: later balances are worked out again. The member isn't
     * told; the office is, and the audit report keeps what was removed and why.
     */
    public function destroy(Request $request): never
    {
        $db = Database::tenant();
        $t = Ledger::find($db, (int) $request->param('id')) ?? Response::error('That transaction doesn\'t exist.', 404);
        if (Auth::isMe((int) $t['user_id'])) {
            Response::error('You can\'t delete entries on your own account. Ask another super admin.', 403);
        }
        if ($why = Ledger::fixable($t)) {
            Response::error($why, 422);
        }
        $reason = mb_substr($request->string('reason'), 0, 255);
        if ($reason === '') {
            Response::error('Say why it is being deleted: it is kept in the audit report.', 422, ['reason' => 'Required']);
        }
        Periods::check($t['value_date']);
        $who = $db->prepare('SELECT full_name FROM users WHERE id = ?');
        $who->execute([$t['user_id']]);
        $name = (string) $who->fetchColumn();
        Approvals::gate($request, [self::class, 'destroy'], 'deletion', 'money', abs(Money::fromDecimal((string) $t['amount'])),
            'Delete a ' . str_replace('_', ' ', $t['type']) . ' of ' . Notify::money(ltrim($t['amount'], '-')) . " on $name's {$t['account']}: $reason",
            (int) $t['user_id']);
        try {
            Database::transaction(function (\PDO $db) use ($t) {
                $db->prepare('DELETE FROM transactions WHERE id = ?')->execute([$t['id']]);
                Ledger::rebalance($db, (int) $t['user_id'], $t['account']);
            });
        } catch (LedgerException $e) {
            Response::error($e->getMessage(), 422);
        }
        Audit::log('transaction.deleted', 'transaction', (int) $t['id'], [
            'member' => (int) $t['user_id'], 'account' => $t['account'], 'type' => $t['type'], 'amount' => $t['amount'],
            'value_date' => $t['value_date'], 'reference' => $t['reference'], 'narration' => $t['narration'], 'reason' => $reason,
        ]);
        Response::ok(['balances' => Ledger::balances((int) $t['user_id'])]);
    }

    private function findUser(int $id): array
    {
        $stmt = Database::tenant()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: Response::error('Member not found.', 404);
    }

    private function account(Request $request): ?string
    {
        $account = $request->string('account');
        return in_array($account, Ledger::ACCOUNTS, true) ? $account : null;
    }

    private function page(Request $request): int
    {
        return max(1, (int) $request->query('page', 1));
    }

    /**
     * POST /api/members/{id}/slow-payer { slow: bool, note } (super admin, or loans permission) -
     * the office marks a member as a slow payer (their borrowing limit is cut to the Setup share),
     * or lifts the mark. The system never does this by itself.
     */
    public function markSlowPayer(Request $request): never
    {
        $user = $this->findUser((int) $request->param('id'));
        if ($user['role'] !== 'member') {
            Response::error('Only members can be marked.', 422);
        }
        if (Auth::isMe((int) $user['id'])) {
            Response::error('This is your own account. Another admin has to decide.', 403);
        }
        $db = Database::tenant();
        $slow = (bool) $request->input('slow');
        $note = trim((string) $request->input('note', ''));
        $marked = (bool) $user['slow_payer_since'];
        if ($slow === $marked) {
            Response::error($slow ? 'They are already marked as a slow payer.' : 'They aren\'t marked as a slow payer.', 422);
        }
        if ($slow && $note === '') {
            Response::error('Say why (it is shown to the member and kept on their record).', 422, ['note' => 'Required']);
        }
        if ($slow) {
            $db->prepare('UPDATE users SET slow_payer_since = CURDATE(), slow_payer_note = ?, slow_payer_by = ? WHERE id = ?')
                ->execute([mb_substr($note, 0, 255), Auth::id(), $user['id']]);
            $percent = Credit::slowPayerPercent();
            Notify::member((int) $user['id'], 'slow_payer', 'You have been marked as a slow payer',
                "The SACCO office marked you as a slow payer ($note). Until the mark is lifted you can borrow up to $percent% of your usual limit. Talk to the office if something is wrong.", '/loans');
        } else {
            $db->prepare('UPDATE users SET slow_payer_since = NULL, slow_payer_note = NULL, slow_payer_by = NULL WHERE id = ?')->execute([$user['id']]);
            Notify::member((int) $user['id'], 'credit_up', 'Your full borrowing limit is back',
                'The SACCO office lifted the slow-payer mark' . ($note !== '' ? " ($note)" : '') . '. You can borrow up to your full limit again.', '/loans');
        }
        Audit::log($slow ? 'member.slow_payer_marked' : 'member.slow_payer_lifted', 'user', (int) $user['id'], ['note' => $note]);
        Response::ok(['credit' => Credit::standing($db, (int) $user['id'])]);
    }
}
