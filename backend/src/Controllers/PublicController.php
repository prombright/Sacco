<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Earnings;
use Sacco\Support\Settings;
use Sacco\Tenant;

/**
 * What anyone may see about a SACCO before signing in: its terms, for the public homepage and its
 * loan calculator. Rates and rules only - never members, balances or totals.
 */
final class PublicController
{
    /** GET /api/public/terms */
    public function terms(Request $request): never
    {
        $sacco = Tenant::fromRequest();
        if (!$sacco || $sacco['status'] !== 'active') {
            Response::error('SACCO not found.', 404);
        }
        // Signed out, the database is the SACCO this web address belongs to (Tenant::current)
        $db = Database::tenant();
        $rules = Loans::rules();

        Response::ok([
            'sacco' => Tenant::publicInfo($sacco) + ['about' => $sacco['about'] ?? null],
            'loan_products' => array_map(fn ($p) => [
                'name' => $p['name'],
                'description' => $p['description'],
                'interest_rate_monthly' => $p['interest_rate_monthly'],
                'max_months' => $p['max_months'],
                'savings_multiplier' => $p['savings_multiplier'],
                'processing_fee_percent' => $p['processing_fee_percent'],
                'min_guarantors' => $p['min_guarantors'],
            ], Loans::products($db)),
            'min_member_months' => $rules['min_member_months'],
            'dividend_rate_percent' => Earnings::dividendRate(),
            'profit_basis' => Settings::get('profit_basis', 'average_savings'),
            'share_price' => Settings::get('share_price', '0'),
            'min_shares' => (int) Settings::get('min_shares', '0'),
            'savings_min_balance' => Settings::get('savings_min_balance', '0'),
            'withdrawal_fee_percent' => Settings::get('withdrawal_fee_percent', '0'),
            'membership_fee_annual' => Settings::get('membership_fee_annual', '0'),
        ]);
    }
}
