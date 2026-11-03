<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Audit;
use Sacco\Support\Money;

/**
 * Loan products (normal, emergency, school fees...), each with its own rate, term, limit,
 * processing fee and guarantor rules. Admins see them; a super admin adds and changes them.
 * Changing a product affects new applications only - every loan keeps the terms it was given.
 */
final class ProductController
{
    /** GET /api/loan-products?all=1 */
    public function index(Request $request): never
    {
        Response::ok(['products' => Loans::products(Database::tenant(), !$request->query('all'))]);
    }

    /** POST /api/loan-products */
    public function store(Request $request): never
    {
        $data = $this->validated($request);
        $db = Database::tenant();
        $db->prepare(
            'INSERT INTO loan_products (name, description, interest_rate_monthly, max_months, savings_multiplier, processing_fee_percent, min_guarantors, guarantee_cover_percent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array_values($data));
        $id = (int) $db->lastInsertId();
        Audit::log('loan_product.created', 'loan_product', $id, $data);
        Response::ok(['product' => $this->find($id)], 201);
    }

    /** PATCH /api/loan-products/{id} { ..., status? } */
    public function update(Request $request): never
    {
        $id = (int) $request->param('id');
        $before = $this->find($id);
        $data = $this->validated($request);
        $status = in_array($request->string('status'), ['active', 'archived'], true) ? $request->string('status') : $before['status'];
        Database::tenant()->prepare(
            'UPDATE loan_products SET name = ?, description = ?, interest_rate_monthly = ?, max_months = ?, savings_multiplier = ?,
                processing_fee_percent = ?, min_guarantors = ?, guarantee_cover_percent = ?, status = ? WHERE id = ?'
        )->execute([...array_values($data), $status, $id]);
        Audit::log('loan_product.updated', 'loan_product', $id, ['before' => $before, 'after' => $data + ['status' => $status]]);
        Response::ok(['product' => $this->find($id)]);
    }

    private function find(int $id): array
    {
        $stmt = Database::tenant()->prepare('SELECT * FROM loan_products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: Response::error('Loan product not found.', 404);
        return Loans::presentProduct($row);
    }

    private function validated(Request $request): array
    {
        $errors = [];
        $name = mb_substr($request->string('name'), 0, 80);
        if ($name === '') {
            $errors['name'] = 'Name the product, e.g. "Emergency loan".';
        }
        $decimal = function (string $field, float $min, float $max, string $message) use ($request, &$errors): string {
            $c = Money::parse($request->input($field));
            if ($c === null || $c < $min * 100 || $c > $max * 100) {
                $errors[$field] = $message;
                return '0';
            }
            return Money::toDecimal($c);
        };
        $int = function (string $field, int $min, int $max, string $message) use ($request, &$errors): int {
            $v = filter_var($request->input($field), FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
            if ($v === false) {
                $errors[$field] = $message;
                return 0;
            }
            return $v;
        };
        $data = [
            'name' => $name,
            'description' => mb_substr($request->string('description'), 0, 255) ?: null,
            'interest_rate_monthly' => $decimal('interest_rate_monthly', 0, 10, 'Interest between 0% and 10% a month.'),
            'max_months' => $int('max_months', 1, 120, 'Between 1 and 120 months.'),
            'savings_multiplier' => $decimal('savings_multiplier', 0.1, 20, 'Between 0.1 and 20 times savings.'),
            'processing_fee_percent' => $decimal('processing_fee_percent', 0, 20, 'A fee between 0% and 20%.'),
            'min_guarantors' => $int('min_guarantors', 0, 10, 'Between 0 and 10 guarantors.'),
            'guarantee_cover_percent' => $int('guarantee_cover_percent', 0, 300, 'Between 0% and 300%.'),
        ];
        if ($errors) {
            Response::error(reset($errors), 422, $errors);
        }
        return $data;
    }
}
