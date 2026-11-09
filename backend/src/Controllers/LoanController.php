<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Auth\Roles;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\LoanMath;
use Sacco\Loans\Loans;
use Sacco\Support\Audit;
use Sacco\Support\LedgerException;
use Sacco\Support\Money;
use Sacco\Support\Notify;
use Sacco\Support\Savings;

/**
 * Loans from application to the last repayment.
 *
 * Member: get a quote, apply with guarantors, add a guarantor if one declines, cancel.
 * Guarantor: accept or decline (accepting ties up that much of their savings).
 * Admin: approve or reject, record the disbursement (cheque), record and reverse repayments.
 * Nobody acts as admin on their own loan.
 */
final class LoanController
{
    /** GET /api/loans/quote?product_id=&amount=&months= - products, limit, rules and a schedule to preview */
    public function quote(Request $request): never
    {
        $db = Database::tenant();
        $products = Loans::products($db);
        $productId = (int) $request->query('product_id', 0) ?: ($products[0]['id'] ?? null);
        $rules = Loans::productRules($db, $productId);
        $eligibility = Loans::eligibility((int) Auth::id(), null, $productId);
        $amount = Money::parse($request->query('amount', ''));
        $months = (int) $request->query('months', 0);

        $schedule = null;
        if ($amount !== null && $amount > 0 && $amount <= Loans::MAX_CENTS && $months >= 1 && $months <= $rules['max_months']) {
            $rows = LoanMath::schedule($amount, $rules['interest_rate_monthly'], $months, date('Y-m-d'));
            $schedule = [
                'installment' => Money::toDecimal($rows[0]['total']),
                'total_interest' => Money::toDecimal(array_sum(array_column($rows, 'interest'))),
                'total_repayable' => Money::toDecimal(array_sum(array_column($rows, 'total'))),
                'rows' => array_map(fn ($r) => [
                    'number' => $r['number'],
                    'due_date' => $r['due_date'],
                    'principal' => Money::toDecimal($r['principal']),
                    'interest' => Money::toDecimal($r['interest']),
                    'total' => Money::toDecimal($r['total']),
                    'balance_after' => Money::toDecimal($r['balance_after']),
                ], $rows),
            ];
        }

        $membership = Loans::membership(Database::tenant(), (int) Auth::id());
        $fee = $amount ? self::fee($amount, $rules['processing_fee_percent']) : 0;
        if ($schedule) {
            $schedule['processing_fee'] = Money::toDecimal($fee);
            $schedule['you_receive'] = Money::toDecimal($amount - $fee);
        }
        Response::ok([
            'products' => $products,
            'rules' => $rules,
            'eligibility' => array_map([Money::class, 'toDecimal'], $eligibility),
            'membership' => $membership + ['wait_reason' => Loans::borrowerWaitReason(Database::tenant(), (int) Auth::id())],
            'schedule' => $schedule,
        ]);
    }

    /** POST /api/loans { amount, months, purpose, guarantors: [{ user_id, amount }] } */
    public function store(Request $request): never
    {
        $me = (int) Auth::id();
        $errors = [];

        $productId = (int) $request->input('product_id', 0);
        $product = $productId ? array_values(array_filter(Loans::products(Database::tenant()), fn ($p) => $p['id'] === $productId))[0] ?? null : null;
        if (!$product) {
            Response::error('Choose a type of loan.', 422, ['product_id' => 'Required']);
        }
        $rules = Loans::productRules(Database::tenant(), $productId);

        if ($wait = Loans::borrowerWaitReason(Database::tenant(), $me)) {
            Response::error($wait, 422);
        }

        $amount = Money::parse($request->input('amount'));
        $months = (int) $request->input('months', 0);
        $purpose = mb_substr($request->string('purpose'), 0, 255);

        if ($amount === null || $amount <= 0) {
            $errors['amount'] = 'Enter the amount you want to borrow.';
        } elseif ($amount > Loans::MAX_CENTS) {
            $errors['amount'] = 'That amount is too large.';
        } else {
            $limit = Loans::eligibility($me, null, $productId)['limit'];
            if ($amount > $limit) {
                $errors['amount'] = 'You can borrow up to ' . number_format($limit / 100) . ' right now.';
            }
        }
        if ($months < 1 || $months > $rules['max_months']) {
            $errors['months'] = "Choose between 1 and {$rules['max_months']} months.";
        }
        if ($purpose === '') {
            $errors['purpose'] = 'Say briefly what the loan is for.';
        }

        $guarantors = $this->guarantors($request->input('guarantors', []), $me, $errors);
        if (!$errors && $amount !== null) {
            $needed = intdiv($amount * $rules['guarantee_cover_percent'], 100);
            $pledged = array_sum(array_column($guarantors, 'amount'));
            if (count($guarantors) < $rules['min_guarantors']) {
                $errors['guarantors'] = "Choose at least {$rules['min_guarantors']} guarantor" . ($rules['min_guarantors'] === 1 ? '' : 's') . '.';
            } elseif ($pledged < $needed) {
                $errors['guarantors'] = 'Your guarantors\' amounts must add up to at least ' . number_format($needed / 100) . '.';
            }
        }

        $db = Database::tenant();
        $inProgress = $db->prepare("SELECT loan_no FROM loans WHERE user_id = ? AND status IN ('pending_guarantors', 'pending_approval', 'approved') LIMIT 1");
        $inProgress->execute([$me]);
        if ($no = $inProgress->fetchColumn()) {
            Response::error("You already have an application in progress ($no). Wait for it to be decided, or cancel it.", 422);
        }
        if ($errors) {
            Response::error(reset($errors), 422, $errors);
        }

        $loanId = Database::transaction(function (\PDO $db) use ($me, $amount, $months, $purpose, $rules, $guarantors) {
            $db->prepare('INSERT INTO loans (user_id, product_id, amount, months, interest_rate_monthly, interest_method, purpose) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$me, $rules['product_id'], Money::toDecimal($amount), $months, $rules['interest_rate_monthly'], $rules['interest_method'], $purpose]);
            $id = (int) $db->lastInsertId();
            $db->prepare('UPDATE loans SET loan_no = ? WHERE id = ?')->execute(['L' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
            $insert = $db->prepare('INSERT INTO loan_guarantors (loan_id, guarantor_id, amount, requested_amount) VALUES (?, ?, ?, ?)');
            foreach ($guarantors as $g) {
                $insert->execute([$id, $g['user_id'], Money::toDecimal($g['amount']), Money::toDecimal($g['amount'])]);
            }
            Loans::advance($db, $id); // straight to approval when the SACCO needs no guarantors
            return $id;
        });
        Audit::log('loan.applied', 'loan', $loanId, ['amount' => Money::toDecimal($amount), 'months' => $months]);
        $borrower = Auth::user()['full_name'];
        foreach ($guarantors as $g) {
            Notify::member($g['user_id'], 'guarantee_request', "$borrower asks you to guarantee a loan",
                "$borrower has applied for a loan of " . Notify::money(Money::toDecimal($amount)) . " and asks you to guarantee " . Notify::money(Money::toDecimal($g['amount'])) . '. Open Loans to agree or decline.', '/loans');
        }

        Response::ok(['loan' => $this->detail($loanId)], 201);
    }

    /** GET /api/me/loans */
    public function mine(Request $request): never
    {
        $stmt = Database::tenant()->prepare('SELECT id FROM loans WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([Auth::id()]);
        Response::ok(['loans' => array_map(fn ($id) => $this->summary((int) $id), $stmt->fetchAll(\PDO::FETCH_COLUMN))]);
    }

    /** GET /api/loans?status=&q= (admin) */
    public function index(Request $request): never
    {
        $db = Database::tenant();
        $where = [];
        $args = [];
        $status = $request->string('status');
        if ($status !== '') {
            $where[] = 'l.status = ?';
            $args[] = $status;
        }
        $q = $request->string('q');
        if ($q !== '') {
            $where[] = '(u.full_name LIKE ? OR u.account_no LIKE ? OR l.loan_no LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%");
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $db->prepare("SELECT l.id FROM loans l JOIN users u ON u.id = l.user_id $sqlWhere ORDER BY l.id DESC LIMIT 200");
        $stmt->execute($args);

        $counts = $db->query('SELECT status, COUNT(*) FROM loans GROUP BY status')->fetchAll(\PDO::FETCH_KEY_PAIR);

        Response::ok([
            'loans' => array_map(fn ($id) => $this->summary((int) $id), $stmt->fetchAll(\PDO::FETCH_COLUMN)),
            'counts' => array_map('intval', $counts),
        ]);
    }

    /** GET /api/loans/{id} - the borrower and admins see everything; a guarantor sees the outline */
    public function show(Request $request): never
    {
        $loan = $this->find((int) $request->param('id'));
        $user = Auth::user();
        $isOwner = (int) $loan['user_id'] === (int) $user['id'];
        $isAdmin = Roles::allows($user['role'], [Roles::ADMIN]);
        if (!$isOwner && !$isAdmin) {
            $stmt = Database::tenant()->prepare('SELECT 1 FROM loan_guarantors WHERE loan_id = ? AND guarantor_id = ?');
            $stmt->execute([$loan['id'], $user['id']]);
            if (!$stmt->fetchColumn()) {
                Response::error('Loan not found.', 404);
            }
            Response::ok(['loan' => $this->summary((int) $loan['id'])]);
        }
        Response::ok(['loan' => $this->detail((int) $loan['id'])]);
    }

    /** POST /api/loans/{id}/guarantors { user_id, amount } - the borrower asks another member */
    public function addGuarantor(Request $request): never
    {
        $loan = $this->find((int) $request->param('id'));
        if ((int) $loan['user_id'] !== Auth::id()) {
            Response::error('Only the borrower can add guarantors.', 403);
        }
        if ($loan['status'] !== 'pending_guarantors') {
            Response::error('This loan doesn\'t need more guarantors.', 422);
        }
        $errors = [];
        $list = $this->guarantors([['user_id' => $request->input('user_id'), 'amount' => $request->input('amount')]], (int) $loan['user_id'], $errors);
        if ($errors) {
            Response::error(reset($errors), 422, $errors);
        }
        $g = $list[0];

        $db = Database::tenant();
        $existing = $db->prepare('SELECT id, status FROM loan_guarantors WHERE loan_id = ? AND guarantor_id = ?');
        $existing->execute([$loan['id'], $g['user_id']]);
        $existing = $existing->fetch();
        if ($existing && $existing['status'] !== 'declined') {
            Response::error('That member is already a guarantor on this loan.', 422);
        }
        if ($existing) {
            // They declined before; asking again (maybe for a different amount) is a fresh request
            $db->prepare("UPDATE loan_guarantors SET status = 'pending', amount = ?, requested_amount = ?, responded_at = NULL WHERE id = ?")
                ->execute([Money::toDecimal($g['amount']), Money::toDecimal($g['amount']), $existing['id']]);
        } else {
            $db->prepare('INSERT INTO loan_guarantors (loan_id, guarantor_id, amount, requested_amount) VALUES (?, ?, ?, ?)')
                ->execute([$loan['id'], $g['user_id'], Money::toDecimal($g['amount']), Money::toDecimal($g['amount'])]);
        }
        Audit::log('loan.guarantor_added', 'loan', (int) $loan['id'], ['guarantor' => $g['user_id'], 'amount' => Money::toDecimal($g['amount'])]);
        Notify::member($g['user_id'], 'guarantee_request', Auth::user()['full_name'] . ' asks you to guarantee a loan',
            Auth::user()['full_name'] . ' asks you to guarantee ' . Notify::money(Money::toDecimal($g['amount'])) . ' of a ' . Notify::money($loan['amount']) . ' loan. Open Loans to agree or decline.', '/loans');

        Response::ok(['loan' => $this->detail((int) $loan['id'])]);
    }

    /** POST /api/loans/{id}/cancel - the borrower (or an admin) withdraws it before disbursement */
    public function cancel(Request $request): never
    {
        $loan = $this->find((int) $request->param('id'));
        $user = Auth::user();
        $isOwner = (int) $loan['user_id'] === (int) $user['id'];
        if (!$isOwner && !Roles::allows($user['role'], [Roles::ADMIN])) {
            Response::error('Loan not found.', 404);
        }
        if (!$isOwner && !Roles::can($user, 'loans')) {
            Response::error('Only the super admin can do this.', 403);
        }
        if (!in_array($loan['status'], ['pending_guarantors', 'pending_approval', 'approved'], true)) {
            Response::error('Only a loan that hasn\'t been paid out can be cancelled.', 422);
        }
        $this->close((int) $loan['id'], 'cancelled', null);
        Audit::log('loan.cancelled', 'loan', (int) $loan['id']);
        Response::ok(['loan' => $this->detail((int) $loan['id'])]);
    }

    /**
     * POST /api/loans/{id}/approve { note?, message? } (admin) - approves, and sends the member the
     * message (usually: come and pick up your cheque)
     */
    public function approve(Request $request): never
    {
        $loan = $this->findForAdmin((int) $request->param('id'));
        if ($loan['status'] !== 'pending_approval') {
            Response::error('Only a loan whose guarantors have all agreed can be approved.', 422);
        }
        Database::tenant()->prepare("UPDATE loans SET status = 'approved', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?")
            ->execute([Auth::id(), mb_substr($request->string('note'), 0, 255) ?: null, $loan['id']]);
        Audit::log('loan.approved', 'loan', (int) $loan['id']);
        $message = mb_substr($request->string('message'), 0, 2000)
            ?: 'Your loan ' . $loan['loan_no'] . ' of ' . Notify::money($loan['amount']) . ' has been approved. Please pick up your cheque from the SACCO office.';
        Notify::member((int) $loan['user_id'], 'loan_approved', 'Your loan is approved', $message, '/loans/' . $loan['id']);
        Response::ok(['loan' => $this->detail((int) $loan['id'])]);
    }

    /** POST /api/loans/{id}/reject { note } (admin) */
    public function reject(Request $request): never
    {
        $loan = $this->findForAdmin((int) $request->param('id'));
        if (!in_array($loan['status'], ['pending_guarantors', 'pending_approval', 'approved'], true)) {
            Response::error('This loan can no longer be rejected.', 422);
        }
        $note = mb_substr($request->string('note'), 0, 255);
        if ($note === '') {
            Response::error('Give the reason - the member will see it.', 422, ['note' => 'Required']);
        }
        $this->close((int) $loan['id'], 'rejected', $note);
        Audit::log('loan.rejected', 'loan', (int) $loan['id'], ['note' => $note]);
        Notify::member((int) $loan['user_id'], 'loan_rejected', 'Your loan application was not approved',
            'Your application ' . $loan['loan_no'] . ' for ' . Notify::money($loan['amount']) . " was not approved: $note", '/loans/' . $loan['id']);
        Response::ok(['loan' => $this->detail((int) $loan['id'])]);
    }

    /** POST /api/loans/{id}/disburse { disbursed_on, reference } (admin) - the cheque was issued; the schedule starts */
    public function disburse(Request $request): never
    {
        $loan = $this->findForAdmin((int) $request->param('id'));
        if ($loan['status'] !== 'approved') {
            Response::error('Only an approved loan can be paid out.', 422);
        }
        $date = $this->date($request->string('disbursed_on') ?: date('Y-m-d'), 'disbursed_on');
        $reference = mb_substr($request->string('reference'), 0, 80);
        if ($reference === '') {
            Response::error('Enter the cheque number or payment reference.', 422, ['reference' => 'Required']);
        }

        // The product's processing fee comes off the cheque; the loan itself is the full amount
        $fee = self::fee(Money::fromDecimal($loan['amount']), Loans::productRules(Database::tenant(), $loan['product_id'] ? (int) $loan['product_id'] : null)['processing_fee_percent']);
        Database::transaction(function (\PDO $db) use ($loan, $date, $reference, $fee) {
            $db->prepare("UPDATE loans SET status = 'active', disbursed_by = ?, disbursed_on = ?, disbursement_ref = ?, processing_fee = ? WHERE id = ? AND status = 'approved'")
                ->execute([Auth::id(), $date, $reference, Money::toDecimal($fee), $loan['id']]);
            Loans::createSchedule($db, $loan, $date);
        });
        Audit::log('loan.disbursed', 'loan', (int) $loan['id'], ['on' => $date, 'reference' => $reference, 'fee' => Money::toDecimal($fee)]);
        Notify::member((int) $loan['user_id'], 'loan_disbursed', 'Your loan has been paid out',
            'Cheque ' . $reference . ' for ' . Notify::money(Money::toDecimal(Money::fromDecimal($loan['amount']) - $fee)) . ' has been issued for loan ' . $loan['loan_no']
            . '. Your repayment schedule starts now; see it under Loans.', '/loans/' . $loan['id']);
        Response::ok(['loan' => $this->detail((int) $loan['id'])]);
    }

    /**
     * POST /api/loans/existing (admin) - a loan from the old records, already paid out before this
     * system: { user_id, product_id, amount, months, interest_rate_monthly?, disbursed_on,
     * reference?, purpose?, repaid_so_far? }. It becomes an active loan with its schedule from the
     * real payout date; what was already repaid is recorded as one repayment, so late
     * instalments show as late. No guarantor workflow - the old paperwork covers that.
     */
    public function recordExisting(Request $request): never
    {
        $userId = (int) $request->input('user_id');
        if ($userId === Auth::id()) {
            Response::error('You can\'t record a loan on your own account. Ask another admin.', 403);
        }
        $db = Database::tenant();
        $u = $db->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'member'");
        $u->execute([$userId]);
        $u->fetch() ?: Response::error('Choose the member.', 422, ['user_id' => 'Required']);

        $productId = (int) $request->input('product_id');
        $rules = Loans::productRules($db, $productId ?: null);
        if (!$rules['product_id']) {
            Response::error('Choose the type of loan.', 422, ['product_id' => 'Required']);
        }
        $amount = Money::parse($request->input('amount'));
        if ($amount === null || $amount <= 0 || $amount > Loans::MAX_CENTS) {
            Response::error('Enter the amount that was lent.', 422, ['amount' => 'Required']);
        }
        $months = (int) $request->input('months');
        if ($months < 1 || $months > 120) {
            Response::error('Enter the number of months (1 to 120).', 422, ['months' => 'Invalid']);
        }
        $rateInput = $request->string('interest_rate_monthly');
        $rate = $rateInput !== '' ? Money::parse($rateInput) : Money::parse($rules['interest_rate_monthly']);
        if ($rate === null || $rate < 0 || $rate > 10_00) {
            Response::error('Interest between 0% and 10% a month.', 422, ['interest_rate_monthly' => 'Invalid']);
        }
        $disbursedOn = $this->date($request->string('disbursed_on'), 'disbursed_on');
        $repaid = Money::parse($request->input('repaid_so_far', '0') ?: '0');
        if ($repaid === null || $repaid < 0) {
            Response::error('Enter how much has been repaid (0 if nothing).', 422, ['repaid_so_far' => 'Invalid']);
        }

        $loanId = Database::transaction(function (\PDO $db) use ($userId, $rules, $amount, $months, $rate, $disbursedOn, $repaid, $request) {
            $db->prepare(
                "INSERT INTO loans (user_id, product_id, source, amount, months, interest_rate_monthly, interest_method, purpose, status, decided_by, decided_at, decision_note, disbursed_by, disbursed_on, disbursement_ref)
                 VALUES (?, ?, 'old_records', ?, ?, ?, 'reducing_balance', ?, 'active', ?, NOW(), 'Brought in from the old records', ?, ?, ?)"
            )->execute([
                $userId, $rules['product_id'], Money::toDecimal($amount), $months, Money::toDecimal($rate),
                mb_substr($request->string('purpose'), 0, 255) ?: 'From the old records', Auth::id(), Auth::id(), $disbursedOn,
                mb_substr($request->string('reference'), 0, 80) ?: null,
            ]);
            $id = (int) $db->lastInsertId();
            $db->prepare('UPDATE loans SET loan_no = ? WHERE id = ?')->execute(['L' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
            $loan = $db->query("SELECT * FROM loans WHERE id = $id")->fetch();
            Loans::createSchedule($db, $loan, $disbursedOn);
            if ($repaid > 0) {
                $left = Loans::position($db, $id)['total_left'];
                if ($repaid > $left) {
                    throw new LedgerException('That is more than the whole loan with its interest (' . number_format($left / 100) . '). If it is fully repaid, there is no need to record it.');
                }
                // Dated at the payout: it stands for repayments made before this system, so it must
                // not clear penalties charged later, and its interest belongs to those earlier months
                $db->prepare("INSERT INTO loan_payments (loan_id, amount, paid_on, reference, narration, created_by) VALUES (?, ?, ?, 'Old records', ?, ?)")
                    ->execute([$id, Money::toDecimal($repaid), $disbursedOn, 'Total repaid before this system, as at ' . date('j M Y'), Auth::id()]);
            }
            Loans::applyPayments($db, $id);
            return $id;
        });
        Audit::log('loan.brought_in', 'loan', $loanId, ['member' => $userId, 'amount' => Money::toDecimal($amount), 'repaid' => Money::toDecimal($repaid)]);
        $this->tellAboutLoan(['id' => $loanId, 'user_id' => $userId], 'loan_disbursed', 'Your loan is now in the app',
            'Your loan of ' . Notify::cents($amount) . " from the SACCO's earlier records has been added, with " . Notify::cents($repaid) . ' already repaid.');
        Response::ok(['loan' => $this->detail($loanId)], 201);
    }

    /**
     * POST /api/loans/{id}/settle { reference? } (admin) - the member pays the loan off today: the
     * principal, unpaid charges, and interest only for instalments already due and the month now
     * running. Later months' interest is taken off the schedule, then the payment closes the loan.
     */
    public function settle(Request $request): never
    {
        $loan = $this->findForAdmin((int) $request->param('id'));
        if ($loan['status'] !== 'active') {
            Response::error('Only an active loan can be paid off.', 422);
        }
        $reference = mb_substr($request->string('reference'), 0, 80) ?: null;
        $result = Database::transaction(function (\PDO $db) use ($loan, $reference) {
            $db->prepare('SELECT id FROM loans WHERE id = ? FOR UPDATE')->execute([$loan['id']]);
            $quote = Loans::settlementQuote($db, (int) $loan['id']);
            $waived = Loans::waiveFutureInterest($db, (int) $loan['id'], $quote['keep']);
            $db->prepare('INSERT INTO loan_payments (loan_id, amount, paid_on, reference, narration, created_by) VALUES (?, ?, CURDATE(), ?, ?, ?)')
                ->execute([$loan['id'], Money::toDecimal($quote['amount']), $reference, 'Paid off early', Auth::id()]);
            Loans::applyPayments($db, (int) $loan['id']);
            $db->prepare('UPDATE loans SET settled_early_on = CURDATE() WHERE id = ?')->execute([$loan['id']]);
            return ['paid' => $quote['amount'], 'waived' => $waived];
        });
        Audit::log('loan.settled_early', 'loan', (int) $loan['id'], ['amount' => Money::toDecimal($result['paid']), 'interest_waived' => Money::toDecimal($result['waived'])]);
        $this->tellAboutLoan($loan, 'loan_repayment', "Loan {$loan['loan_no']} is paid off",
            'You paid off the loan early with ' . Notify::cents($result['paid']) . ($result['waived'] > 0 ? ', and ' . Notify::cents($result['waived']) . ' of interest for later months was taken off.' : '.'));
        Response::ok(['loan' => $this->detail((int) $loan['id'])]);
    }

    /**
     * POST /api/loans/penalties/run (admin) - charges every active loan with late instalments the
     * penalty % of what is late. At most once per loan per calendar month, so running it twice is safe.
     */
    public function runPenalties(Request $request): never
    {
        $percent = Money::parse(Loans::penaltyPercent());
        if (!$percent) {
            Response::error('The late-payment penalty is set to 0%. Set it on the Rules page first.', 422);
        }
        $result = Database::transaction(function (\PDO $db) use ($percent) {
            $loans = $db->query("SELECT l.id, l.loan_no, l.user_id, u.full_name FROM loans l JOIN users u ON u.id = l.user_id WHERE l.status = 'active'")->fetchAll();
            $already = $db->prepare("SELECT 1 FROM loan_charges WHERE loan_id = ? AND type = 'penalty' AND waived_at IS NULL AND DATE_FORMAT(charged_on, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')");
            $insert = $db->prepare("INSERT INTO loan_charges (loan_id, type, amount, charged_on, note, created_by) VALUES (?, 'penalty', ?, CURDATE(), ?, ?)");
            $charged = [];
            foreach ($loans as $l) {
                $late = Loans::position($db, (int) $l['id'])['overdue_instalments'];
                if ($late <= 0) {
                    continue;
                }
                $already->execute([$l['id']]);
                if ($already->fetchColumn()) {
                    continue;
                }
                $penalty = (int) (ceil($late * ($percent / 10000) / LoanMath::UNIT) * LoanMath::UNIT);
                if ($penalty <= 0) {
                    continue;
                }
                $insert->execute([$l['id'], Money::toDecimal($penalty), Loans::penaltyPercent() . '% of ' . number_format($late / 100) . ' late', Auth::id()]);
                Loans::applyPayments($db, (int) $l['id']);
                $charged[] = ['loan_no' => $l['loan_no'], 'borrower' => $l['full_name'], 'penalty' => Money::toDecimal($penalty), 'id' => (int) $l['id'], 'user_id' => (int) $l['user_id'], 'late' => $late];
            }
            return $charged;
        });
        Audit::log('loan.penalties_charged', null, null, ['count' => count($result)]);
        foreach ($result as $c) {
            $this->tellAboutLoan(['id' => $c['id'], 'user_id' => $c['user_id']], 'loan_penalty', 'Late-payment charge of ' . Notify::money($c['penalty']),
                Notify::cents($c['late']) . " of loan {$c['loan_no']} is late, so a " . Loans::penaltyPercent() . '% charge of ' . Notify::money($c['penalty']) . ' was added.');
        }
        Response::ok(['charged' => $result]);
    }

    /** POST /api/loan-charges/{id}/waive { reason } (admin) */
    public function waiveCharge(Request $request): never
    {
        $db = Database::tenant();
        $stmt = $db->prepare('SELECT * FROM loan_charges WHERE id = ?');
        $stmt->execute([(int) $request->param('id')]);
        $charge = $stmt->fetch() ?: Response::error('Charge not found.', 404);
        $loan = $this->findForAdmin((int) $charge['loan_id']);
        if ($charge['waived_at']) {
            Response::error('This charge has already been waived.', 422);
        }
        $reason = mb_substr($request->string('reason'), 0, 200);
        if ($reason === '') {
            Response::error('Say why this charge is being waived.', 422, ['reason' => 'Required']);
        }
        Database::transaction(function (\PDO $db) use ($charge, $loan, $reason) {
            $db->prepare('UPDATE loan_charges SET waived_at = NOW(), waived_by = ?, note = CONCAT(IFNULL(note, \'\'), ?) WHERE id = ?')
                ->execute([Auth::id(), " · Waived: $reason", $charge['id']]);
            Loans::applyPayments($db, (int) $loan['id']);
        });
        Audit::log('loan.charge_waived', 'loan', (int) $loan['id'], ['charge' => (int) $charge['id'], 'reason' => $reason]);
        $this->tellAboutLoan($loan, 'loan_repayment', 'A charge on your loan was waived',
            'The ' . Notify::money($charge['amount']) . " charge on loan {$loan['loan_no']} was taken off: $reason.");
        Response::ok(['loan' => $this->detail((int) $loan['id'])]);
    }

    /** POST /api/loans/{id}/payments { amount, paid_on, reference?, narration? } (admin) */
    public function recordPayment(Request $request): never
    {
        $loan = $this->findForAdmin((int) $request->param('id'));
        if ($loan['status'] !== 'active') {
            Response::error('Repayments can only be recorded on an active loan.', 422);
        }
        $cents = Money::parse($request->input('amount'));
        if ($cents === null || $cents <= 0) {
            Response::error('Enter an amount greater than zero.', 422, ['amount' => 'Required']);
        }
        $paidOn = $this->date($request->string('paid_on') ?: date('Y-m-d'), 'paid_on');
        if ($paidOn < $loan['disbursed_on']) {
            Response::error('A repayment can\'t be dated before the loan was paid out.', 422, ['paid_on' => 'Too early']);
        }

        $receipt = Database::transaction(fn (\PDO $db) => $this->postPayment(
            $db, $loan, $cents, $paidOn,
            mb_substr($request->string('reference'), 0, 80) ?: null,
            mb_substr($request->string('narration'), 0, 255) ?: null
        ));
        Audit::log('loan.payment_recorded', 'loan_payment', $receipt['payment_id'], ['loan' => (int) $loan['id'], 'amount' => Money::toDecimal($cents)]);
        $this->tellAboutLoan($loan, 'loan_repayment', 'Loan repayment of ' . Notify::cents($cents) . ' received',
            'We received ' . Notify::cents($cents) . " for loan {$loan['loan_no']} on " . date('j M Y', strtotime($paidOn)) . '.');
        Response::ok(['loan' => $this->detail((int) $loan['id']), 'receipt' => $receipt], 201);
    }

    /**
     * GET /api/loans/{id}/payment-preview?amount=&paid_on= (admin) - what a repayment would do,
     * worked out exactly as recording it would, then undone: how it splits between penalties,
     * interest and principal, what is still owed after it, and the next amount due.
     */
    public function previewPayment(Request $request): never
    {
        $loan = $this->findForAdmin((int) $request->param('id'));
        if ($loan['status'] !== 'active') {
            Response::error('Repayments can only be recorded on an active loan.', 422);
        }
        $cents = Money::parse($request->query('amount', ''));
        if ($cents === null || $cents <= 0) {
            Response::ok(['receipt' => null]);
        }
        $paidOn = $request->string('paid_on') ?: date('Y-m-d');
        if (!\DateTime::createFromFormat('!Y-m-d', $paidOn) || $paidOn < $loan['disbursed_on'] || $paidOn > date('Y-m-d')) {
            $paidOn = date('Y-m-d');
        }
        $db = Database::tenant();
        $db->beginTransaction();
        try {
            $receipt = $this->postPayment($db, $loan, $cents, $paidOn, null, null);
        } finally {
            $db->rollBack();
        }
        Response::ok(['receipt' => $receipt]);
    }

    /**
     * Records a repayment and applies it. Returns its receipt: the split, what is still owed and
     * what falls due next. Call inside a transaction.
     */
    private function postPayment(\PDO $db, array $loan, int $cents, string $paidOn, ?string $reference, ?string $narration): array
    {
        $db->prepare('SELECT id FROM loans WHERE id = ? FOR UPDATE')->execute([$loan['id']]);
        $before = Loans::position($db, (int) $loan['id']);
        if ($cents > $before['total_left']) {
            throw new LedgerException('That is more than the ' . number_format($before['total_left'] / 100) . ' still owed on this loan.');
        }
        $db->prepare('INSERT INTO loan_payments (loan_id, amount, paid_on, reference, narration, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$loan['id'], Money::toDecimal($cents), $paidOn, $reference, $narration, Auth::id()]);
        $paymentId = (int) $db->lastInsertId();
        Loans::applyPayments($db, (int) $loan['id']);
        $after = Loans::position($db, (int) $loan['id']);

        $split = $db->prepare('SELECT part, SUM(amount) FROM loan_payment_allocations WHERE payment_id = ? GROUP BY part');
        $split->execute([$paymentId]);
        $parts = array_map([Money::class, 'fromDecimal'], $split->fetchAll(\PDO::FETCH_KEY_PAIR));
        $status = $db->prepare('SELECT status FROM loans WHERE id = ?');
        $status->execute([$loan['id']]);

        return [
            'payment_id' => $paymentId,
            'amount' => Money::toDecimal($cents),
            'paid_on' => $paidOn,
            'penalties' => Money::toDecimal(($parts['penalty'] ?? 0) + ($parts['fee'] ?? 0)),
            'interest' => Money::toDecimal($parts['interest'] ?? 0),
            'principal' => Money::toDecimal($parts['principal'] ?? 0),
            'owed_before' => Money::toDecimal($before['total_left']),
            'owed_after' => Money::toDecimal($after['total_left']),
            'principal_after' => Money::toDecimal($after['principal_left']),
            'overdue_after' => Money::toDecimal($after['overdue']),
            'next_due_date' => $after['next_due_date'],
            'next_due_amount' => Money::toDecimal($after['next_due_amount']),
            'closed' => $status->fetchColumn() === 'closed',
        ];
    }

    /** POST /api/loan-payments/{id}/reverse { reason } (admin) */
    public function reversePayment(Request $request): never
    {
        $db = Database::tenant();
        $stmt = $db->prepare('SELECT p.*, (SELECT r.id FROM loan_payments r WHERE r.reversal_of = p.id) AS reversed_by FROM loan_payments p WHERE p.id = ?');
        $stmt->execute([(int) $request->param('id')]);
        $payment = $stmt->fetch() ?: Response::error('Repayment not found.', 404);
        $loan = $this->findForAdmin((int) $payment['loan_id']);
        if ($payment['reversal_of'] || $payment['reversed_by']) {
            Response::error('This repayment has already been reversed.', 422);
        }
        $reason = mb_substr($request->string('reason'), 0, 255);
        if ($reason === '') {
            Response::error('Say why this repayment is being reversed.', 422, ['reason' => 'Required']);
        }

        Database::transaction(function (\PDO $db) use ($payment, $loan, $reason) {
            $db->prepare('SELECT id FROM loans WHERE id = ? FOR UPDATE')->execute([$loan['id']]);
            $db->prepare('INSERT INTO loan_payments (loan_id, amount, paid_on, reference, narration, reversal_of, created_by) VALUES (?, ?, CURDATE(), ?, ?, ?, ?)')
                ->execute([$loan['id'], Money::toDecimal(-Money::fromDecimal($payment['amount'])), $payment['reference'], $reason, $payment['id'], Auth::id()]);
            Loans::applyPayments($db, (int) $loan['id']);
        });
        Audit::log('loan.payment_reversed', 'loan_payment', (int) $payment['id'], ['reason' => $reason]);
        $this->tellAboutLoan($loan, 'money_reversed', 'A loan repayment was reversed',
            'The repayment of ' . Notify::money($payment['amount']) . " on loan {$loan['loan_no']} dated " . date('j M Y', strtotime($payment['paid_on'])) . " was reversed: $reason.");
        Response::ok(['loan' => $this->detail((int) $loan['id'])]);
    }

    /** GET /api/me/guarantees - requests to guarantee others' loans, and what's pledged */
    public function myGuarantees(Request $request): never
    {
        $db = Database::tenant();
        $me = (int) Auth::id();
        $stmt = $db->prepare(
            "SELECT g.id, g.amount, g.requested_amount, g.status, g.created_at, g.responded_at, l.id AS loan_id, l.loan_no, l.amount AS loan_amount,
                    l.months, l.purpose, l.status AS loan_status, u.full_name AS borrower_name
             FROM loan_guarantors g JOIN loans l ON l.id = g.loan_id JOIN users u ON u.id = l.user_id
             WHERE g.guarantor_id = ? AND g.status <> 'withdrawn'
             ORDER BY g.status = 'pending' DESC, g.id DESC"
        );
        $stmt->execute([$me]);
        Response::ok([
            'guarantees' => $stmt->fetchAll(),
            'pledged' => Money::toDecimal(Loans::pledged($db, $me)),
            'free_savings' => Money::toDecimal(Savings::free($db, $me)),
            'wait_reason' => Loans::guarantorWaitReason($db, $me, '', true),
        ]);
    }

    /**
     * POST /api/guarantees/{id}/accept { amount? } - the guarantor agrees, to the amount asked or
     * to an amount of their own (up to the loan's size); it must fit in their free savings
     */
    public function accept(Request $request): never
    {
        $guarantee = $this->myPendingGuarantee((int) $request->param('id'));
        $offered = $request->input('amount');
        $pledge = $offered === null || $offered === ''
            ? Money::fromDecimal($guarantee['requested_amount'] ?? $guarantee['amount'])
            : Money::parse($offered);
        if ($pledge === null || $pledge <= 0) {
            Response::error('Enter the amount you will guarantee.', 422, ['amount' => 'Required']);
        }
        $loanAmount = Money::fromDecimal($guarantee['loan_amount']);
        if ($pledge > $loanAmount) {
            Response::error('You can\'t guarantee more than the loan itself (' . number_format($loanAmount / 100) . ').', 422, ['amount' => 'Too much']);
        }

        Database::transaction(function (\PDO $db) use ($guarantee, $pledge) {
            if ($wait = Loans::guarantorWaitReason($db, (int) Auth::id(), '', true)) {
                throw new LedgerException($wait);
            }
            // Lock the guarantor so two acceptances can't both spend the same free savings
            $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([Auth::id()]);
            $free = Savings::free($db, (int) Auth::id());
            if ($pledge > $free) {
                throw new LedgerException('You have ' . number_format($free / 100) . ' free in your savings (not already pledged or being withdrawn), so you can guarantee up to that.');
            }
            $db->prepare("UPDATE loan_guarantors SET status = 'accepted', amount = ?, responded_at = NOW() WHERE id = ? AND status = 'pending'")
                ->execute([Money::toDecimal($pledge), $guarantee['id']]);
            Loans::advance($db, (int) $guarantee['loan_id']);
        });
        Audit::log('loan.guarantee_accepted', 'loan', (int) $guarantee['loan_id'], ['amount' => Money::toDecimal($pledge), 'asked' => $guarantee['requested_amount']]);
        $this->tellBorrower((int) $guarantee['loan_id'], Auth::user()['full_name'] . ' agreed to guarantee ' . Notify::money(Money::toDecimal($pledge)) . ' of your loan.');
        Response::ok();
    }

    /** POST /api/guarantees/{id}/decline */
    public function decline(Request $request): never
    {
        $guarantee = $this->myPendingGuarantee((int) $request->param('id'));
        Database::tenant()->prepare("UPDATE loan_guarantors SET status = 'declined', responded_at = NOW() WHERE id = ? AND status = 'pending'")
            ->execute([$guarantee['id']]);
        Audit::log('loan.guarantee_declined', 'loan', (int) $guarantee['loan_id']);
        $this->tellBorrower((int) $guarantee['loan_id'], Auth::user()['full_name'] . ' declined to guarantee your loan. You can ask another member from your loan page.');
        Response::ok();
    }

    /** GET /api/members/search?q= - active members to pick as guarantors (names only, never balances) */
    public function searchMembers(Request $request): never
    {
        $q = $request->string('q');
        if (mb_strlen($q) < 2) {
            Response::ok(['members' => []]);
        }
        $stmt = Database::tenant()->prepare(
            "SELECT id, full_name, account_no FROM users
             WHERE status = 'active' AND role = 'member' AND id <> ? AND (full_name LIKE ? OR account_no LIKE ? OR employee_no LIKE ?)
             ORDER BY full_name LIMIT 10"
        );
        $stmt->execute([Auth::id(), "%$q%", "%$q%", "%$q%"]);
        Response::ok(['members' => array_map(fn ($m) => ['id' => (int) $m['id'], 'full_name' => $m['full_name'], 'account_no' => $m['account_no']], $stmt->fetchAll())]);
    }

    // ----------------------------------------------------------------------

    /** A guarantor's answer, to the borrower */
    /** Tells the borrower what happened to their loan, and where it now stands */
    private function tellAboutLoan(array $loan, string $kind, string $title, string $text): void
    {
        $p = Loans::position(Database::tenant(), (int) $loan['id']);
        $text .= $p['total_left'] > 0
            ? ' Still to pay: ' . Notify::cents($p['total_left'])
                . ($p['next_due_date'] ? '. Next: ' . Notify::cents($p['next_due_amount']) . ' by ' . date('j M Y', strtotime($p['next_due_date'])) : '') . '.'
            : ' The loan is fully repaid.';
        Notify::member((int) $loan['user_id'], $kind, $title, $text, '/loans/' . $loan['id']);
    }

    private function tellBorrower(int $loanId, string $text): void
    {
        $stmt = Database::tenant()->prepare('SELECT user_id, status FROM loans WHERE id = ?');
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch();
        if (!$loan) {
            return;
        }
        if ($loan['status'] === 'pending_approval') {
            $text .= ' All your guarantors have agreed, so it now goes to the SACCO office for approval.';
        }
        Notify::member((int) $loan['user_id'], 'guarantor_answer', 'A guarantor answered', $text, '/loans/' . $loanId);
    }

    /**
     * Does the borrower qualify? Each SACCO rule checked against today's figures, so the admin can
     * see at a glance before approving. level: pass, warn (allowed, but worth a look), fail.
     */
    private function assessment(\PDO $db, array $loan): array
    {
        $userId = (int) $loan['user_id'];
        $rules = Loans::productRules($db, $loan['product_id'] ? (int) $loan['product_id'] : null);
        $checks = [];

        $m = Loans::membership($db, $userId);
        $need = $rules['min_member_months'];
        $checks[] = [
            'label' => 'Saved long enough',
            'level' => $m['months'] >= $need ? 'pass' : 'fail',
            'detail' => $m['since'] ? "Saving for {$m['months']} months (since " . date('j M Y', strtotime($m['since'])) . "); the rule is $need." : 'No savings recorded yet.',
        ];

        $e = Loans::eligibility($userId, (int) $loan['id'], $loan['product_id'] ? (int) $loan['product_id'] : null);
        $amount = Money::fromDecimal($loan['amount']);
        $checks[] = [
            'label' => 'Within their loan limit',
            'level' => $amount <= $e['limit'] ? 'pass' : 'fail',
            'detail' => "Limit " . number_format($e['limit'] / 100) . " ({$rules['savings_multiplier']} × savings of " . number_format($e['savings'] / 100)
                . ($e['owed'] ? ', less ' . number_format($e['owed'] / 100) . ' owed on other loans' : '') . '); asking for ' . number_format($amount / 100) . '.',
        ];

        $short = Loans::guaranteeShortfall($db, $loan);
        $checks[] = [
            'label' => 'Guarantors cover the loan',
            'level' => $short === null ? 'pass' : 'fail',
            'detail' => $short === null
                ? 'Enough guarantors have agreed, for enough of the loan.'
                : 'Still needs ' . ($short['guarantors'] ? $short['guarantors'] . ' more guarantor(s)' : '') . ($short['guarantors'] && $short['amount'] ? ' and ' : '') . ($short['amount'] ? number_format($short['amount'] / 100) . ' more in guarantees' : '') . '.',
        ];

        $others = $db->prepare("SELECT id, loan_no FROM loans WHERE user_id = ? AND status = 'active' AND id <> ?");
        $others->execute([$userId, $loan['id']]);
        $late = [];
        $count = 0;
        foreach ($others->fetchAll() as $o) {
            $count++;
            $p = Loans::position($db, (int) $o['id']);
            if ($p['overdue'] > 0) {
                $late[] = $o['loan_no'] . ' (' . number_format($p['overdue'] / 100) . ' late)';
            }
        }
        $checks[] = [
            'label' => 'Repaying other loans on time',
            'level' => $late ? 'warn' : 'pass',
            'detail' => $late ? 'Behind on ' . implode(', ', $late) . '.' : ($count ? "$count other loan(s), all up to date." : 'No other loans.'),
        ];

        if (($minCapital = Savings::minShareCapital()) > 0) {
            $sp = Savings::sharePosition($db, $userId);
            $checks[] = [
                'label' => 'Holds the minimum shares',
                'level' => Money::fromDecimal($sp['still_needed']) === 0 ? 'pass' : 'fail',
                'detail' => "Has {$sp['shares']} of the {$sp['min_shares']} shares required"
                    . (Money::fromDecimal($sp['still_needed']) ? '; ' . number_format(Money::fromDecimal($sp['still_needed']) / 100) . ' more in share capital is needed.' : '.'),
            ];
        }

        $fails = count(array_filter($checks, fn ($c) => $c['level'] === 'fail'));
        $warns = count(array_filter($checks, fn ($c) => $c['level'] === 'warn'));
        return [
            'verdict' => $fails ? 'does_not_qualify' : ($warns ? 'qualifies_with_concerns' : 'qualifies'),
            'checks' => $checks,
        ];
    }

    /** A percentage fee on an amount, rounded to whole currency units */
    private static function fee(int $amount, string $percent): int
    {
        $hundredths = (int) Money::parse($percent ?: '0');
        return (int) (round($amount * ($hundredths / 10000) / LoanMath::UNIT) * LoanMath::UNIT);
    }

    private function find(int $id): array
    {
        $stmt = Database::tenant()->prepare('SELECT * FROM loans WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: Response::error('Loan not found.', 404);
    }

    private function findForAdmin(int $id): array
    {
        $loan = $this->find($id);
        if ((int) $loan['user_id'] === Auth::id()) {
            Response::error('This is your own loan. Another admin has to handle it.', 403);
        }
        return $loan;
    }

    private function myPendingGuarantee(int $id): array
    {
        $stmt = Database::tenant()->prepare(
            'SELECT g.*, l.status AS loan_status, l.amount AS loan_amount FROM loan_guarantors g JOIN loans l ON l.id = g.loan_id WHERE g.id = ? AND g.guarantor_id = ?'
        );
        $stmt->execute([$id, Auth::id()]);
        $g = $stmt->fetch() ?: Response::error('Request not found.', 404);
        if ($g['status'] !== 'pending' || $g['loan_status'] !== 'pending_guarantors') {
            Response::error('This request has already been answered or is no longer needed.', 422);
        }
        return $g;
    }

    /** Cancel or reject: guarantors who hadn't answered are no longer being asked */
    private function close(int $loanId, string $status, ?string $note): void
    {
        Database::transaction(function (\PDO $db) use ($loanId, $status, $note) {
            $db->prepare('UPDATE loans SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = COALESCE(?, decision_note) WHERE id = ?')
                ->execute([$status, Auth::id(), $note, $loanId]);
            $db->prepare("UPDATE loan_guarantors SET status = 'withdrawn' WHERE loan_id = ? AND status = 'pending'")->execute([$loanId]);
        });
    }

    private function date(string $value, string $field): string
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            Response::error('Enter a valid date.', 422, [$field => 'Invalid']);
        }
        if ($value > date('Y-m-d')) {
            Response::error('The date can\'t be in the future.', 422, [$field => 'Future']);
        }
        return $value;
    }

    /**
     * @param array $errors filled with problems
     * @return list<array{user_id: int, amount: int}>
     */
    private function guarantors(mixed $raw, int $borrowerId, array &$errors): array
    {
        if (!is_array($raw)) {
            $errors['guarantors'] = 'Choose your guarantors.';
            return [];
        }
        $list = [];
        $seen = [];
        $db = Database::tenant();
        $lookup = $db->prepare("SELECT full_name FROM users WHERE id = ? AND status = 'active' AND role = 'member'");
        foreach ($raw as $g) {
            $userId = (int) ($g['user_id'] ?? 0);
            $amount = Money::parse($g['amount'] ?? null);
            if ($userId === $borrowerId) {
                $errors['guarantors'] = 'You can\'t guarantee your own loan.';
                continue;
            }
            if (isset($seen[$userId])) {
                $errors['guarantors'] = 'Each guarantor can only be chosen once.';
                continue;
            }
            $lookup->execute([$userId]);
            $name = $lookup->fetchColumn();
            if (!$name) {
                $errors['guarantors'] = 'One of the guarantors isn\'t an active member (office accounts can\'t guarantee).';
                continue;
            }
            if ($wait = Loans::guarantorWaitReason($db, $userId, $name)) {
                $errors['guarantors'] = $wait;
                continue;
            }
            if ($amount === null || $amount <= 0) {
                $errors['guarantors'] = 'Enter how much each guarantor is guaranteeing.';
                continue;
            }
            $seen[$userId] = true;
            $list[] = ['user_id' => $userId, 'amount' => $amount];
        }
        return $list;
    }

    /** A loan for lists: borrower, amounts and where it stands */
    private function summary(int $id): array
    {
        $db = Database::tenant();
        $stmt = $db->prepare(
            'SELECT l.*, u.full_name AS borrower_name, u.account_no AS borrower_account_no, p.name AS product_name
             FROM loans l JOIN users u ON u.id = l.user_id LEFT JOIN loan_products p ON p.id = l.product_id WHERE l.id = ?'
        );
        $stmt->execute([$id]);
        $l = $stmt->fetch();
        $position = in_array($l['status'], ['active', 'closed'], true) ? Loans::position($db, $id) : null;
        $shortfall = $l['status'] === 'pending_guarantors' ? Loans::guaranteeShortfall($db, $l) : null;

        return [
            'id' => (int) $l['id'],
            'loan_no' => $l['loan_no'],
            'user_id' => (int) $l['user_id'],
            'borrower_name' => $l['borrower_name'],
            'borrower_account_no' => $l['borrower_account_no'],
            'amount' => $l['amount'],
            'months' => (int) $l['months'],
            'interest_rate_monthly' => rtrim(rtrim($l['interest_rate_monthly'], '0'), '.'),
            'purpose' => $l['purpose'],
            'status' => $l['status'],
            'decision_note' => $l['decision_note'],
            'disbursed_on' => $l['disbursed_on'],
            'disbursement_ref' => $l['disbursement_ref'],
            'product_name' => $l['product_name'],
            'processing_fee' => $l['processing_fee'],
            'settled_early_on' => $l['settled_early_on'],
            'closed_on' => $l['closed_on'],
            'created_at' => $l['created_at'],
            'position' => $position ? array_map(fn ($v) => is_int($v) ? Money::toDecimal($v) : $v, $position) : null,
            'guarantee_shortfall' => $shortfall ? ['guarantors' => $shortfall['guarantors'], 'amount' => Money::toDecimal($shortfall['amount'])] : null,
        ];
    }

    /** Everything about a loan: guarantors, schedule, repayments */
    private function detail(int $id): array
    {
        $db = Database::tenant();
        $loan = $this->summary($id);

        $g = $db->prepare('SELECT g.id, g.guarantor_id, u.full_name, u.account_no, g.amount, g.requested_amount, g.status, g.responded_at FROM loan_guarantors g JOIN users u ON u.id = g.guarantor_id WHERE g.loan_id = ? ORDER BY g.id');
        $g->execute([$id]);
        $loan['guarantors'] = $g->fetchAll();

        $i = $db->prepare('SELECT number, due_date, principal, interest, principal_paid, interest_paid FROM loan_installments WHERE loan_id = ? ORDER BY number');
        $i->execute([$id]);
        $loan['installments'] = $i->fetchAll();

        // Before disbursement, the schedule it would have if paid out today
        if (!$loan['installments'] && in_array($loan['status'], ['pending_guarantors', 'pending_approval', 'approved'], true)) {
            $loan['projected'] = array_map(fn ($r) => [
                'number' => $r['number'],
                'due_date' => $r['due_date'],
                'principal' => Money::toDecimal($r['principal']),
                'interest' => Money::toDecimal($r['interest']),
                'total' => Money::toDecimal($r['total']),
            ], LoanMath::schedule(Money::fromDecimal($loan['amount']), $loan['interest_rate_monthly'], $loan['months'], date('Y-m-d')));
        }

        $p = $db->prepare(
            'SELECT p.id, p.amount, p.paid_on, p.reference, p.narration, p.reversal_of, u.full_name AS created_by_name, p.created_at,
                    (SELECT r.id FROM loan_payments r WHERE r.reversal_of = p.id) AS reversed_by
             FROM loan_payments p LEFT JOIN users u ON u.id = p.created_by WHERE p.loan_id = ? ORDER BY p.id DESC'
        );
        $p->execute([$id]);
        $loan['payments'] = $p->fetchAll();

        // How each repayment that stands was split
        $s = $db->prepare('SELECT payment_id, part, SUM(amount) AS amount FROM loan_payment_allocations WHERE loan_id = ? GROUP BY payment_id, part');
        $s->execute([$id]);
        $splits = [];
        foreach ($s->fetchAll() as $row) {
            $part = $row['part'] === 'fee' ? 'penalty' : $row['part'];
            $splits[$row['payment_id']][$part] = Money::toDecimal(Money::fromDecimal($splits[$row['payment_id']][$part] ?? '0') + Money::fromDecimal($row['amount']));
        }
        foreach ($loan['payments'] as &$payment) {
            $payment['split'] = $splits[$payment['id']] ?? null;
        }
        unset($payment);

        $c = $db->prepare('SELECT id, type, amount, paid, charged_on, note, waived_at FROM loan_charges WHERE loan_id = ? ORDER BY id DESC');
        $c->execute([$id]);
        $loan['charges'] = $c->fetchAll();

        // What paying it all off today would cost (admins, active loans)
        $loan['settlement'] = null;
        if ($loan['status'] === 'active' && Roles::allows(Auth::user()['role'], [Roles::ADMIN])) {
            $q = Loans::settlementQuote($db, $id);
            $loan['settlement'] = ['amount' => Money::toDecimal($q['amount']), 'interest_saved' => Money::toDecimal($q['interest_saved'])];
        }

        // Before payout: the fee that will come off the cheque
        if (in_array($loan['status'], ['pending_guarantors', 'pending_approval', 'approved'], true)) {
            $l = $db->prepare('SELECT product_id FROM loans WHERE id = ?');
            $l->execute([$id]);
            $pid = $l->fetchColumn();
            $loan['processing_fee'] = Money::toDecimal(self::fee(Money::fromDecimal($loan['amount']), Loans::productRules($db, $pid ? (int) $pid : null)['processing_fee_percent']));
        }

        // For admins deciding: does the borrower qualify today?
        $loan['assessment'] = null;
        if (in_array($loan['status'], ['pending_guarantors', 'pending_approval', 'approved'], true) && Roles::allows(Auth::user()['role'], [Roles::ADMIN])) {
            $l = $db->prepare('SELECT * FROM loans WHERE id = ?');
            $l->execute([$id]);
            $loan['assessment'] = $this->assessment($db, $l->fetch());
        }

        $loan['borrower_eligibility'] = Roles::allows(Auth::user()['role'], [Roles::ADMIN])
            ? array_map([Money::class, 'toDecimal'], Loans::eligibility((int) $loan['user_id'], $id))
            : null;

        return $loan;
    }
}
