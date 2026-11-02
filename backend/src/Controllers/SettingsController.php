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
            'loan_interest_rate_monthly' => ['Loan interest, % per month', $decimal(0, 10)],
            'loan_savings_multiplier' => ['Loan limit, times savings', $decimal(0, 20)],
            'loan_max_months' => ['Longest loan, months', $int(1, 120)],
            'loan_min_guarantors' => ['Guarantors needed', $int(0, 10)],
            'loan_guarantee_cover_percent' => ['Guarantees must cover, % of the loan', $int(0, 300)],
            'loan_min_member_months' => ['Months of saving before a loan', $int(0, 120)],
            'guarantor_min_member_months' => ['Months of membership to be a guarantor', $int(0, 120)],
            'share_price' => ['Price of one share', $decimal(0, 1_000_000_000)],
        ];
    }

    /** GET /api/settings (admin) */
    public function index(Request $request): never
    {
        $out = [];
        foreach (self::fields() as $key => [$label]) {
            $out[] = ['key' => $key, 'label' => $label, 'value' => Settings::get($key)];
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
