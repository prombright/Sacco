<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Support\Money;

/**
 * Loan security (collateral): items a borrower leaves with the SACCO, such as a land title or a
 * car logbook. The office records them while the loan is being given or running, and marks each
 * returned once the loan is repaid. The borrower sees them on their loan page.
 */
final class LoanSecurityController
{
    public const KINDS = ['Land title', 'Car logbook', 'Household items', 'Business equipment', 'Fixed deposit', 'Other'];

    /** The loan's securities, for its page */
    public static function forLoan(int $loanId): array
    {
        $stmt = Database::tenant()->prepare(
            'SELECT s.id, s.kind, s.description, s.value, s.reference, s.kept_at, s.created_at, s.returned_on,
                    c.full_name AS created_by_name, r.full_name AS returned_by_name
             FROM loan_securities s LEFT JOIN users c ON c.id = s.created_by LEFT JOIN users r ON r.id = s.returned_by
             WHERE s.loan_id = ? ORDER BY s.id'
        );
        $stmt->execute([$loanId]);
        return array_map(fn ($s) => ['id' => (int) $s['id']] + $s, $stmt->fetchAll());
    }

    /** POST /api/loans/{id}/securities { kind, description, value?, reference?, kept_at? } */
    public function store(Request $request): never
    {
        $loan = $this->loan((int) $request->param('id'));
        if (in_array($loan['status'], ['closed', 'rejected', 'cancelled'], true)) {
            Response::error('This loan is ' . $loan['status'] . '; security can only be added to a loan still being given or repaid.', 422);
        }
        $kind = trim($request->string('kind'));
        if (!in_array($kind, self::KINDS, true)) {
            Response::error('Choose what kind of security it is.', 422, ['kind' => 'Required']);
        }
        $description = mb_substr(trim($request->string('description')), 0, 255);
        if ($description === '') {
            Response::error('Describe the security, e.g. "Plot 12, Block 4, Mukono".', 422, ['description' => 'Required']);
        }
        $value = null;
        if (trim((string) $request->input('value')) !== '') {
            $cents = Money::parse($request->input('value'));
            if ($cents === null || $cents < 0) {
                Response::error('Enter the estimated value as an amount, or leave it empty.', 422, ['value' => 'Invalid']);
            }
            $value = Money::toDecimal($cents);
        }
        $reference = mb_substr(trim($request->string('reference')), 0, 80) ?: null;
        $keptAt = mb_substr(trim($request->string('kept_at')), 0, 120) ?: null;

        $db = Database::tenant();
        $db->prepare('INSERT INTO loan_securities (loan_id, kind, description, value, reference, kept_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$loan['id'], $kind, $description, $value, $reference, $keptAt, Auth::id()]);
        $id = (int) $db->lastInsertId();
        Audit::log('loan.security_added', 'loan', (int) $loan['id'], ['security_id' => $id, 'kind' => $kind, 'description' => $description, 'value' => $value]);
        Response::ok(['securities' => self::forLoan((int) $loan['id'])], 201);
    }

    /** POST /api/loans/{id}/securities/{sid}/return - handed back to the member */
    public function markReturned(Request $request): never
    {
        $loan = $this->loan((int) $request->param('id'));
        $s = $this->security((int) $loan['id'], (int) $request->param('sid'));
        if ($s['returned_on']) {
            Response::error('This security was already returned.', 422);
        }
        if ($loan['status'] === 'active') {
            Response::error('The loan is still being repaid. Security is returned once it is fully repaid.', 422);
        }
        Database::tenant()->prepare('UPDATE loan_securities SET returned_on = CURDATE(), returned_by = ? WHERE id = ?')->execute([Auth::id(), $s['id']]);
        Audit::log('loan.security_returned', 'loan', (int) $loan['id'], ['security_id' => (int) $s['id'], 'kind' => $s['kind']]);
        Response::ok(['securities' => self::forLoan((int) $loan['id'])]);
    }

    /** DELETE /api/loans/{id}/securities/{sid} - entered by mistake (kept in the activity log) */
    public function destroy(Request $request): never
    {
        $loan = $this->loan((int) $request->param('id'));
        $s = $this->security((int) $loan['id'], (int) $request->param('sid'));
        Database::tenant()->prepare('DELETE FROM loan_securities WHERE id = ?')->execute([$s['id']]);
        Audit::log('loan.security_removed', 'loan', (int) $loan['id'], ['kind' => $s['kind'], 'description' => $s['description'], 'value' => $s['value']]);
        Response::ok(['securities' => self::forLoan((int) $loan['id'])]);
    }

    private function loan(int $id): array
    {
        $stmt = Database::tenant()->prepare('SELECT id, user_id, status FROM loans WHERE id = ?');
        $stmt->execute([$id]);
        $loan = $stmt->fetch() ?: Response::error('Loan not found.', 404);
        if ((int) $loan['user_id'] === Auth::id()) {
            Response::error('This is your own loan. Another admin has to handle it.', 403);
        }
        return $loan;
    }

    private function security(int $loanId, int $id): array
    {
        $stmt = Database::tenant()->prepare('SELECT * FROM loan_securities WHERE id = ? AND loan_id = ?');
        $stmt->execute([$id, $loanId]);
        return $stmt->fetch() ?: Response::error('Security not found.', 404);
    }
}
