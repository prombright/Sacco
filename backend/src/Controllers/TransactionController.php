<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
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
        $active = $db->prepare("SELECT id FROM loans WHERE user_id = ? AND status = 'active'");
        $active->execute([$id]);
        $owed = 0;
        $next = null;
        foreach ($active->fetchAll(\PDO::FETCH_COLUMN) as $loanId) {
            $p = Loans::position($db, (int) $loanId);
            $owed += $p['total_left'];
            if ($p['next_due_date'] && (!$next || $p['next_due_date'] < $next['date'])) {
                $next = ['date' => $p['next_due_date'], 'amount' => Money::toDecimal($p['next_due_amount'])];
            }
        }
        $requests = $db->prepare("SELECT COUNT(*) FROM loan_guarantors g JOIN loans l ON l.id = g.loan_id WHERE g.guarantor_id = ? AND g.status = 'pending' AND l.status = 'pending_guarantors'");
        $requests->execute([$id]);

        // Dividends that still stand (not reversed): all of them, and the latest
        $dividends = $db->prepare(
            "SELECT t.amount, t.reference, t.value_date FROM transactions t
             WHERE t.user_id = ? AND t.type = 'dividend' AND NOT EXISTS (SELECT 1 FROM transactions r WHERE r.reversal_of = t.id)
             ORDER BY t.id DESC"
        );
        $dividends->execute([$id]);
        $dividends = $dividends->fetchAll();

        Response::ok([
            'dividends_total' => Money::toDecimal(array_sum(array_map(fn ($d) => Money::fromDecimal($d['amount']), $dividends))),
            'last_dividend' => $dividends[0] ?? null,
            'balances' => Ledger::balances($id),
            'pledged' => Money::toDecimal(Loans::pledged($db, $id)),
            'share_price' => Settings::get('share_price', '0'),
            'share_position' => Savings::sharePosition($db, $id),
            'withdrawable' => Money::toDecimal(Savings::withdrawable($db, $id)),
            'loan_owed' => Money::toDecimal($owed),
            'next_repayment' => $next,
            'guarantee_requests' => (int) $requests->fetchColumn(),
            'recent' => Ledger::history($id, null, 1, 5)['transactions'],
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
        $from = $request->string('from') ?: date('Y-01-01');
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
            $open = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND account = ? AND value_date < ?');
            $open->execute([$id, $account, $from]);
            $balance = Money::fromDecimal($open->fetchColumn());
            $opening = $balance;

            $stmt = $db->prepare(
                'SELECT value_date, type, amount, reference, narration FROM transactions
                 WHERE user_id = ? AND account = ? AND value_date BETWEEN ? AND ? ORDER BY value_date, id'
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
            'printed_at' => date('Y-m-d H:i'),
        ];
    }

    /** GET /api/me/transactions?account=&page= */
    public function myTransactions(Request $request): never
    {
        Response::ok(Ledger::history((int) Auth::id(), $this->account($request), $this->page($request)));
    }

    /** GET /api/members/{id} - a member with their balances (admin) */
    public function member(Request $request): never
    {
        $user = $this->findUser((int) $request->param('id'));
        Response::ok([
            'user' => AuthController::present($user),
            'balances' => Ledger::balances((int) $user['id']),
            'share_price' => Settings::get('share_price', '0'),
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
        if ((int) $user['id'] === Auth::id()) {
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

        try {
            $row = Database::transaction(function (\PDO $db) use ($user, $account, $type, $sign, $cents, $valueDate, $narration, $request) {
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

        Response::ok(['transaction' => Ledger::present($row), 'balances' => Ledger::balances((int) $user['id'])], 201);
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
        if ((int) $original['user_id'] === Auth::id()) {
            Response::error('You can\'t reverse entries on your own account. Ask another admin.', 403);
        }

        try {
            $row = Database::transaction(fn (\PDO $db) => Ledger::reverse($db, (int) $original['id'], $reason));
        } catch (LedgerException $e) {
            Response::error($e->getMessage(), 422);
        }
        Audit::log('transaction.reversed', 'transaction', (int) $original['id'], ['reversal' => (int) $row['id'], 'reason' => $reason]);

        Response::ok(['transaction' => Ledger::present($row), 'balances' => Ledger::balances((int) $original['user_id'])], 201);
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
}
