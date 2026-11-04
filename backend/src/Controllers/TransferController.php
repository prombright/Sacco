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
use Sacco\Support\Notify;
use Sacco\Support\Savings;
use Sacco\Support\Settings;

/**
 * Internal transfers: money moved between accounts without passing through the bank.
 *
 * - savings to the member's own shares (buying shares)
 * - savings to another member's savings or shares
 * - shares to another member's shares (selling shares to a colleague)
 * - savings to a loan (the member's own, or one they help repay)
 *
 * The same rules as a withdrawal apply to what leaves: the minimum savings balance stays, pledged
 * savings stay, and members keep their minimum shares.
 */
final class TransferController
{
    /** GET /api/transfers?page= */
    public function index(Request $request): never
    {
        $page = max(1, (int) $request->query('page', 1));
        $per = 30;
        $db = Database::tenant();
        $rows = $db->query(
            'SELECT t.*, f.full_name AS from_name, f.account_no AS from_account_no, r.full_name AS to_name, r.account_no AS to_account_no,
                l.loan_no, c.full_name AS created_by_name
             FROM transfers t JOIN users f ON f.id = t.from_user_id JOIN users r ON r.id = t.to_user_id
             LEFT JOIN loans l ON l.id = t.loan_id LEFT JOIN users c ON c.id = t.created_by
             ORDER BY t.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per)
        )->fetchAll();
        Response::ok([
            'transfers' => $rows,
            'total' => (int) $db->query('SELECT COUNT(*) FROM transfers')->fetchColumn(),
            'page' => $page,
            'per_page' => $per,
        ]);
    }

    /** POST /api/transfers { from_user_id, from_account, to_user_id, to_account, loan_id?, amount, value_date?, reason } */
    public function store(Request $request): never
    {
        $db = Database::tenant();
        $from = $this->member((int) $request->input('from_user_id'), 'from_user_id');
        $to = $this->member((int) ($request->input('to_user_id') ?: $from['id']), 'to_user_id');
        $fromAccount = $request->string('from_account');
        $toAccount = $request->string('to_account');
        if (!in_array($fromAccount, ['savings', 'shares'], true)) {
            Response::error('Choose where the money comes from.', 422, ['from_account' => 'Required']);
        }
        if (!in_array($toAccount, ['savings', 'shares', 'loan'], true)) {
            Response::error('Choose where the money goes.', 422, ['to_account' => 'Required']);
        }
        $same = (int) $from['id'] === (int) $to['id'];
        if ($same && $fromAccount === $toAccount) {
            Response::error('Choose a different account to move it to.', 422, ['to_account' => 'Same']);
        }
        if ($fromAccount === 'shares' && ($toAccount !== 'shares' || $same)) {
            Response::error('Shares can only be transferred to another member\'s shares. They are paid out only when a member leaves.', 422, ['to_account' => 'Not allowed']);
        }
        foreach ([$from, $to] as $u) {
            if ((int) $u['id'] === Auth::id()) {
                Response::error('You can\'t move money on your own account. Ask another admin.', 403);
            }
        }
        $cents = Money::parse($request->input('amount'));
        if ($cents === null || $cents <= 0) {
            Response::error('Enter an amount greater than zero.', 422, ['amount' => 'Required']);
        }
        $date = $request->string('value_date') ?: date('Y-m-d');
        $d = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            Response::error('Enter a valid date, not in the future.', 422, ['value_date' => 'Invalid']);
        }
        $reason = mb_substr($request->string('reason'), 0, 255);
        if ($reason === '') {
            Response::error('Say why the money is being moved.', 422, ['reason' => 'Required']);
        }
        $loan = null;
        if ($toAccount === 'loan') {
            $stmt = $db->prepare("SELECT * FROM loans WHERE id = ? AND user_id = ? AND status = 'active'");
            $stmt->execute([(int) $request->input('loan_id'), $to['id']]);
            $loan = $stmt->fetch() ?: Response::error('Choose an active loan of ' . $to['full_name'] . '.', 422, ['loan_id' => 'Required']);
        }

        try {
            $id = Database::transaction(function (\PDO $db) use ($from, $to, $fromAccount, $toAccount, $loan, $cents, $date, $reason) {
                $db->prepare('SELECT id FROM users WHERE id IN (?, ?) FOR UPDATE')->execute([$from['id'], $to['id']]);
                if ($fromAccount === 'savings') {
                    $room = Savings::withdrawable($db, (int) $from['id']);
                    if ($cents > $room) {
                        throw new LedgerException('Only ' . number_format($room / 100) . ' can move out of ' . $from['full_name'] . '\'s savings: '
                            . number_format(Savings::minBalance() / 100) . ' must always stay, and savings pledged for loans or held for a withdrawal stay too.');
                    }
                } elseif (($min = Savings::minShareCapital()) > 0 && Ledger::balance($db, (int) $from['id'], 'shares') - $cents < $min) {
                    throw new LedgerException($from['full_name'] . ' must keep at least ' . Settings::get('min_shares') . ' shares (' . number_format($min / 100) . ').');
                }
                if ($loan) {
                    $left = Loans::position($db, (int) $loan['id'])['total_left'];
                    if ($cents > $left) {
                        throw new LedgerException("Loan {$loan['loan_no']} has only " . number_format($left / 100) . ' left to pay.');
                    }
                }

                $db->prepare('INSERT INTO transfers (from_user_id, from_account, to_user_id, to_account, loan_id, amount, value_date, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$from['id'], $fromAccount, $to['id'], $toAccount, $loan['id'] ?? null, Money::toDecimal($cents), $date, $reason, Auth::id()]);
                $id = (int) $db->lastInsertId();
                $no = 'T' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
                $where = $toAccount === 'loan' ? "loan {$loan['loan_no']}" : ((int) $to['id'] === (int) $from['id'] ? "own $toAccount" : "{$to['full_name']}'s $toAccount");
                $meta = ['value_date' => $date, 'reference' => "Transfer $no", 'transfer_id' => $id];
                $out = Ledger::post($db, (int) $from['id'], $fromAccount, 'transfer', -$cents, $meta + ['narration' => "To $where: $reason"]);
                $in = null;
                $payment = null;
                if ($loan) {
                    $db->prepare('INSERT INTO loan_payments (loan_id, amount, paid_on, reference, narration, transfer_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$loan['id'], Money::toDecimal($cents), $date, "Transfer $no", "From {$from['full_name']}'s savings: $reason", $id, Auth::id()]);
                    $payment = (int) $db->lastInsertId();
                    Loans::applyPayments($db, (int) $loan['id']);
                } else {
                    $in = Ledger::post($db, (int) $to['id'], $toAccount, 'transfer', $cents, $meta + ['narration' => "From {$from['full_name']}'s $fromAccount: $reason"]);
                }
                $db->prepare('UPDATE transfers SET transfer_no = ?, out_transaction_id = ?, in_transaction_id = ?, loan_payment_id = ? WHERE id = ?')
                    ->execute([$no, $out['id'], $in['id'] ?? null, $payment, $id]);
                return $id;
            });
        } catch (LedgerException $e) {
            Response::error($e->getMessage(), 422, ['amount' => $e->getMessage()]);
        }
        $t = $this->find($id);
        Audit::log('transfer.posted', 'transfer', $id, ['amount' => Money::toDecimal($cents), 'from' => (int) $from['id'], 'to' => (int) $to['id'], 'reason' => $reason]);
        $this->tell($t, false);
        Response::ok(['transfer' => $t], 201);
    }

    /** POST /api/transfers/{id}/reverse { reason } */
    public function reverse(Request $request): never
    {
        $t = $this->find((int) $request->param('id'));
        if ($t['status'] !== 'posted') {
            Response::error('This transfer has already been reversed.', 422);
        }
        $reason = mb_substr($request->string('reason'), 0, 255);
        if ($reason === '') {
            Response::error('Say why this transfer is being reversed.', 422, ['reason' => 'Required']);
        }
        try {
            Database::transaction(function (\PDO $db) use ($t, $reason) {
                $db->prepare('SELECT id FROM transfers WHERE id = ? FOR UPDATE')->execute([$t['id']]);
                if ($t['in_transaction_id']) {
                    Ledger::reverse($db, (int) $t['in_transaction_id'], "Transfer {$t['transfer_no']} reversed: $reason");
                }
                if ($t['loan_payment_id']) {
                    $db->prepare('INSERT INTO loan_payments (loan_id, amount, paid_on, reference, narration, reversal_of, transfer_id, created_by) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?)')
                        ->execute([$t['loan_id'], Money::toDecimal(-Money::fromDecimal($t['amount'])), "Transfer {$t['transfer_no']}", "Reversed: $reason", $t['loan_payment_id'], $t['id'], Auth::id()]);
                    Loans::applyPayments($db, (int) $t['loan_id']);
                }
                Ledger::reverse($db, (int) $t['out_transaction_id'], "Transfer {$t['transfer_no']} reversed: $reason");
                $db->prepare("UPDATE transfers SET status = 'reversed', reversed_by = ?, reversed_on = CURDATE(), reversal_reason = ? WHERE id = ?")
                    ->execute([Auth::id(), $reason, $t['id']]);
            });
        } catch (LedgerException $e) {
            Response::error('It can\'t be reversed: ' . $e->getMessage(), 422);
        }
        Audit::log('transfer.reversed', 'transfer', (int) $t['id'], ['reason' => $reason]);
        $t = $this->find((int) $t['id']);
        $this->tell($t, true);
        Response::ok(['transfer' => $t]);
    }

    /** Both members hear about it */
    private function tell(array $t, bool $reversed): void
    {
        $amount = Notify::money($t['amount']);
        $where = $t['to_account'] === 'loan' ? "loan {$t['loan_no']}" : ($t['from_user_id'] === $t['to_user_id'] ? "your {$t['to_account']}" : "{$t['to_name']}'s {$t['to_account']}");
        $title = $reversed ? "Transfer {$t['transfer_no']} was reversed" : "$amount moved from your {$t['from_account']}";
        $body = $reversed
            ? "The transfer of $amount from your {$t['from_account']} to $where was reversed: {$t['reversal_reason']}. The money is back in your {$t['from_account']}."
            : "$amount went from your {$t['from_account']} to $where. Reason: {$t['reason']}.";
        Notify::member((int) $t['from_user_id'], $reversed ? 'money_reversed' : 'money_out', $title, $body, '/statement');
        if ($t['to_user_id'] !== $t['from_user_id']) {
            $title = $reversed ? "Transfer {$t['transfer_no']} was reversed" : "$amount received from {$t['from_name']}";
            $body = $reversed
                ? "The $amount {$t['from_name']} moved to your " . ($t['to_account'] === 'loan' ? "loan {$t['loan_no']}" : $t['to_account']) . " has been taken back: {$t['reversal_reason']}."
                : "{$t['from_name']} moved $amount from their {$t['from_account']} to your " . ($t['to_account'] === 'loan' ? "loan {$t['loan_no']}" : $t['to_account']) . '.';
            Notify::member((int) $t['to_user_id'], $reversed ? 'money_reversed' : 'money_in', $title, $body, $t['to_account'] === 'loan' ? '/loans/' . $t['loan_id'] : '/statement');
        }
    }

    private function find(int $id): array
    {
        $stmt = Database::tenant()->prepare(
            'SELECT t.*, f.full_name AS from_name, r.full_name AS to_name, l.loan_no FROM transfers t
             JOIN users f ON f.id = t.from_user_id JOIN users r ON r.id = t.to_user_id LEFT JOIN loans l ON l.id = t.loan_id WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $t = $stmt->fetch() ?: Response::error('Transfer not found.', 404);
        foreach (['id', 'from_user_id', 'to_user_id'] as $k) {
            $t[$k] = (int) $t[$k];
        }
        return $t;
    }

    private function member(int $id, string $field): array
    {
        $stmt = Database::tenant()->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'member' AND status = 'active'");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: Response::error('Choose an active member.', 422, [$field => 'Required']);
    }
}
