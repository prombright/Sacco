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

        $yesNo = fn ($v) => in_array($v, ['yes', 'no'], true) ? $v : null;
        return [
            'loan_min_member_months' => ['Months of saving before a loan', $int(0, 120)],
            'guarantor_min_member_months' => ['Months of membership to be a guarantor', $int(0, 120)],
            'loan_penalty_percent' => ['Late-payment penalty, % of what is late (0 = none)', $decimal(0, 50)],
            'credit_limit_rule' => ['How late repayments lower the borrowing limit', fn ($v) => in_array($v, ['sliding', 'fixed'], true) ? $v : null,
                ['sliding' => 'Step by step as the repayment score falls below 80', 'fixed' => 'Only for slow payers (score under 50), straight to the lowest limit']],
            'slow_payer_limit_percent' => ['Lowest borrowing limit for the worst payers, % of the usual limit (100 = no cut)', $int(0, 100)],
            'loan_reminder_days' => ['Remind members this many days before an instalment is due', $int(1, 14)],
            'monthly_statements' => ['A short statement to every member on the 1st of each month', fn ($v) => in_array($v, ['off', 'on'], true) ? $v : null,
                ['off' => 'Off', 'on' => 'On: sent on the 1st, with an email copy where there is one']],
            'loan_reminders' => ['Loan reminders (due soon and late) to members and the super admin', fn ($v) => in_array($v, ['on', 'off'], true) ? $v : null,
                ['on' => 'On', 'off' => 'Paused (e.g. until repayments since an import are entered)']],
            'withdrawal_fee_percent' => ['Withdrawal fee, % of the amount withdrawn', $decimal(0, 50)],
            'withdrawal_fee_mode' => ['How the withdrawal fee is taken', fn ($v) => in_array($v, ['from_amount', 'on_top'], true) ? $v : null,
                ['from_amount' => 'Out of the amount (smaller cheque)', 'on_top' => 'On top (savings go down by amount + fee)']],
            'savings_min_balance' => ['Savings that must always stay in the account', $decimal(0, 1_000_000_000)],
            'share_price' => ['Price of one share', $decimal(0, 1_000_000_000)],
            'min_shares' => ['Shares every member must hold', $int(0, 10_000)],
            'membership_fee_once' => ['Membership fee, paid once (0 = none)', $decimal(0, 100_000_000)],
            'subscription_fee_annual' => ['Subscription every year (0 = none)', $decimal(0, 100_000_000)],
            'dividend_rate_percent' => ['Dividend a year, % of each member\'s share capital', $decimal(0, 100)],
            'profit_basis' => ['Investment profit is shared by', fn ($v) => in_array($v, ['average_savings', 'year_end_savings'], true) ? $v : null,
                ['average_savings' => 'Average savings over the year', 'year_end_savings' => 'Savings at the end of the year']],
            'admins_can_members' => ['Admins may add and change members', $yesNo, ['no' => 'No, view only', 'yes' => 'Yes']],
            'admins_can_money' => ['Admins may record and reverse money (deposits, payroll, transfers, withdrawals, fees, income, expenses, journals)', $yesNo, ['no' => 'No, view only', 'yes' => 'Yes']],
            'admins_can_loans' => ['Admins may approve, pay out and manage loans', $yesNo, ['no' => 'No, view only', 'yes' => 'Yes']],
            'admins_can_notices' => ['Admins may post notices', $yesNo, ['no' => 'No, view only', 'yes' => 'Yes']],
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
            $value = Settings::get($key);
            // A choice never saved yet shows its first option (e.g. loan reminders: On)
            if ($value === '' && !empty($field[2])) {
                $value = (string) array_key_first($field[2]);
            }
            $out[] = ['key' => $key, 'label' => $field[0], 'value' => $value, 'options' => $field[2] ?? null];
        }
        Response::ok(['settings' => $out]);
    }

    /** PATCH /api/settings { key: value, ... } (super admin) */
    public function update(Request $request): never
    {
        $errors = self::apply($request->json());
        if ($errors) {
            Response::error('Some values aren\'t valid.', 422, $errors);
        }
        $this->index($request);
    }

    /** Whether a rule with this key exists */
    public static function has(string $key): bool
    {
        return isset(self::fields()[$key]);
    }

    /** The choices for a rule that has them (value => words), else null */
    public static function options(string $key): ?array
    {
        return self::fields()[$key][2] ?? null;
    }

    /**
     * Checks and saves rule values (the Setup page, or a committee decision); returns errors by
     * key, and saves nothing when there are any.
     */
    public static function apply(array $values): array
    {
        $fields = self::fields();
        $changes = [];
        $errors = [];
        foreach ($values as $key => $value) {
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
            return $errors;
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
        return [];
    }
}
