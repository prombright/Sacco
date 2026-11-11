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
            'INSERT INTO loan_products (name, description, interest_rate_monthly, max_months, savings_multiplier, processing_fee, processing_fee_type, processing_fee_percent, processing_fee_bands, min_guarantors, guarantee_cover_percent,
                min_amount, max_amount, min_member_months, max_open, open_months, allow_top_up)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
                processing_fee = ?, processing_fee_type = ?, processing_fee_percent = ?, processing_fee_bands = ?, min_guarantors = ?, guarantee_cover_percent = ?,
                min_amount = ?, max_amount = ?, min_member_months = ?, max_open = ?, open_months = ?, allow_top_up = ?, status = ? WHERE id = ?'
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
            'processing_fee' => $decimal('processing_fee', 0, 100000000, 'Enter the fee for each loan, or 0.'),
            'processing_fee_type' => in_array($request->string('processing_fee_type'), ['flat', 'percent', 'bands'], true) ? $request->string('processing_fee_type') : 'flat',
            'processing_fee_percent' => $decimal('processing_fee_percent', 0, 20, 'A percentage between 0 and 20.'),
            'processing_fee_bands' => null,
            'min_guarantors' => $int('min_guarantors', 0, 10, 'Between 0 and 10 guarantors.'),
            'guarantee_cover_percent' => $int('guarantee_cover_percent', 0, 300, 'Between 0% and 300%.'),
        ];
        // Fees by loan size: each band from an amount, up to an amount (or no limit), with its fee
        $bands = [];
        foreach ((array) $request->input('processing_fee_bands', []) as $i => $b) {
            $from = Money::parse((string) ($b['from'] ?? ''));
            $toText = trim((string) ($b['to'] ?? ''));
            $to = $toText === '' ? null : Money::parse($toText);
            $fee = Money::parse((string) ($b['fee'] ?? ''));
            if ($from === null || $fee === null || ($toText !== '' && ($to === null || $to <= $from))) {
                $errors['processing_fee_bands'] = 'Band ' . ($i + 1) . ' needs a starting amount, a fee, and an upper amount above the start (or none).';
                break;
            }
            $bands[] = ['from' => Money::toDecimal($from), 'to' => $to === null ? null : Money::toDecimal($to), 'fee' => Money::toDecimal($fee)];
        }
        usort($bands, fn ($a, $b) => Money::parse($a['from']) <=> Money::parse($b['from']));
        foreach ($bands as $i => $b) {
            $next = $bands[$i + 1] ?? null;
            if ($next && ($b['to'] === null || Money::parse($b['to']) >= Money::parse($next['from']))) {
                $errors['processing_fee_bands'] = 'The bands overlap: each must end before the next one starts.';
            }
        }
        if ($data['processing_fee_type'] === 'bands' && !$bands) {
            $errors['processing_fee_bands'] = 'Add at least one band.';
        }
        $data['processing_fee_bands'] = $bands ? json_encode($bands) : null;

        // The type's own limits; empty means none (or the SACCO's general rule)
        $optionalMoney = function (string $field) use ($request, &$errors): ?string {
            $v = trim((string) $request->input($field, ''));
            if ($v === '') {
                return null;
            }
            $c = Money::parse($v);
            if ($c === null || $c <= 0) {
                $errors[$field] = 'Enter an amount, or leave it empty.';
                return null;
            }
            return Money::toDecimal($c);
        };
        $data['min_amount'] = $optionalMoney('min_amount');
        $data['max_amount'] = $optionalMoney('max_amount');
        if ($data['min_amount'] !== null && $data['max_amount'] !== null && Money::fromDecimal($data['min_amount']) > Money::fromDecimal($data['max_amount'])) {
            $errors['max_amount'] = 'The most must be more than the least.';
        }
        $months = trim((string) $request->input('min_member_months', ''));
        $data['min_member_months'] = $months === '' ? null : $int('min_member_months', 0, 120, 'Between 0 and 120 months, or empty for the SACCO\'s rule.');
        $data['max_open'] = $int('max_open', 0, 10, 'Between 0 (no limit) and 10.');
        $open = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('open_months', [])), fn ($m) => $m >= 1 && $m <= 12)));
        sort($open);
        $data['open_months'] = $open && count($open) < 12 ? implode(',', $open) : null;
        $data['allow_top_up'] = $request->input('allow_top_up') ? 1 : 0;
        if ($errors) {
            Response::error(reset($errors), 422, $errors);
        }
        return $data;
    }
}
