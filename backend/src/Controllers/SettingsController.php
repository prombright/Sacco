<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Support\Money;
use Sacco\Support\Settings;

/** The SACCO's rules. Admins can see them; only a super admin changes them. */
final class SettingsController
{
    /** key => [label, check returning the cleaned value or null when invalid] */
    private static function fields(): array
    {
        $decimal = fn (float $min, float $max) => function ($v) use ($min, $max) {
            $c = Money::parse($v);
            return $c !== null && $c >= $min * 100 && $c <= $max * 100 ? rtrim(rtrim(Money::toDecimal($c), '0'), '.') : null;
        };
        $int = fn (int $min, int $max) => fn ($v) => filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) !== false ? (string) (int) $v : null;

        return [
            'loan_min_member_months' => ['Months of saving before a loan', $int(0, 120)],
            'guarantor_min_member_months' => ['Months of membership to be a guarantor', $int(0, 120)],
            'loan_penalty_percent' => ['Late-payment penalty, % of what is late (0 = none)', $decimal(0, 50)],
            'withdrawal_fee_percent' => ['Withdrawal fee, % of the amount withdrawn', $decimal(0, 50)],
            'withdrawal_fee_mode' => ['How the withdrawal fee is taken', fn ($v) => in_array($v, ['from_amount', 'on_top'], true) ? $v : null,
                ['from_amount' => 'Out of the amount (smaller cheque)', 'on_top' => 'On top (savings go down by amount + fee)']],
            'savings_min_balance' => ['Savings that must always stay in the account', $decimal(0, 1_000_000_000)],
            'share_price' => ['Price of one share', $decimal(0, 1_000_000_000)],
            'min_shares' => ['Shares every member must hold', $int(0, 10_000)],
            'dividend_rate_percent' => ['Dividend a year, % of each member\'s share capital', $decimal(0, 100)],
            'profit_basis' => ['Investment profit is shared by', fn ($v) => in_array($v, ['average_savings', 'year_end_savings'], true) ? $v : null,
                ['average_savings' => 'Average savings over the year', 'year_end_savings' => 'Savings at the end of the year']],
            'profit_credit_account' => ['Members\' profit share is added to', fn ($v) => in_array($v, ['savings', 'shares'], true) ? $v : null,
                ['savings' => 'Savings', 'shares' => 'Shares']],
        ];
    }

    /** A rule's name in words, for messages */
    public static function label(string $key): string
    {
        return self::fields()[$key][0] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /** GET /api/settings (admin) */
    public function index(Request $request): never
    {
        $out = [];
        foreach (self::fields() as $key => $field) {
            $out[] = ['key' => $key, 'label' => $field[0], 'value' => Settings::get($key), 'options' => $field[2] ?? null];
        }
        Response::ok(['settings' => $out]);
    }

    /** PATCH /api/settings { key: value, ... } (super admin) */
    public function update(Request $request): never
    {
        $fields = self::fields();
        $changes = [];
        $errors = [];
        foreach ($request->json() as $key => $value) {
            if (!isset($fields[$key])) {
                continue;
            }
            $clean = $fields[$key][1](is_scalar($value) ? (string) $value : '');
            if ($clean === null) {
                $errors[$key] = 'Not a valid value.';
            } elseif ($clean !== Settings::get($key)) {
                $changes[$key] = ['from' => Settings::get($key), 'to' => $clean];
            }
        }
        if ($errors) {
            Response::error('Some values aren\'t valid.', 422, $errors);
        }
        $stmt = Database::tenant()->prepare(
            'INSERT INTO settings (`key`, `value`, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)'
        );
        foreach ($changes as $key => $change) {
            $stmt->execute([$key, $change['to'], Auth::id()]);
        }
        if ($changes) {
            Audit::log('settings.changed', null, null, $changes);
        }
        Settings::forget();
        $this->index($request);
    }
}
