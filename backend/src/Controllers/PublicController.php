<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Loans;
use Sacco\Support\Audit;
use Sacco\Support\Earnings;
use Sacco\Support\Settings;
use Sacco\Tenant;

/**
 * What anyone may see about a SACCO before signing in: its terms, for the public homepage and its
 * loan calculator. Rates and rules only - never members, balances or totals.
 */
final class PublicController
{
    private const LOGO_DIR = __DIR__ . '/../../storage/logos';
    private const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /** GET /api/public/manifest - lets phones install the app, under the SACCO's name and colour */
    public function manifest(Request $request): never
    {
        $sacco = Tenant::fromRequest();
        $name = $sacco['name'] ?? 'SACCO';
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo json_encode([
            'name' => $name,
            'short_name' => $sacco['short_name'] ?: $name,
            'description' => $sacco['slogan'] ?? 'Savings and credit',
            'start_url' => '../../',
            'scope' => '../../',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => $sacco['brand_color'] ?? '#2b35af',
            'icons' => [
                ['src' => '../../brand/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '../../brand/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '../../brand/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** GET /api/public/logo - the SACCO's logo, when one has been uploaded */
    public function logo(Request $request): never
    {
        $sacco = Tenant::current();
        foreach (self::LOGO_TYPES as $type => $ext) {
            $file = self::LOGO_DIR . '/' . ($sacco['code'] ?? '_') . '.' . $ext;
            if ($sacco && is_file($file)) {
                header('Content-Type: ' . $type);
                header('Cache-Control: public, max-age=86400');
                header('X-Content-Type-Options: nosniff');
                readfile($file);
                exit;
            }
        }
        Response::error('No logo', 404);
    }

    /** POST /api/settings/logo (multipart, field "logo") - the super admin uploads the SACCO's logo */
    public function uploadLogo(Request $request): never
    {
        $f = $_FILES['logo'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            Response::error('Choose a picture of the logo.', 422);
        }
        if ($f['size'] > 1024 * 1024) {
            Response::error('The logo must be under 1 MB.', 422);
        }
        // Trust the picture's own contents, not its name
        $info = @getimagesize($f['tmp_name']);
        $type = $info['mime'] ?? '';
        if (!isset(self::LOGO_TYPES[$type])) {
            Response::error('Use a PNG, JPG or WebP picture.', 422);
        }
        $sacco = Tenant::current();
        if (!is_dir(self::LOGO_DIR)) {
            mkdir(self::LOGO_DIR, 0755, true);
        }
        foreach (self::LOGO_TYPES as $ext) {
            @unlink(self::LOGO_DIR . "/{$sacco['code']}.$ext");
        }
        if (!move_uploaded_file($f['tmp_name'], self::LOGO_DIR . "/{$sacco['code']}." . self::LOGO_TYPES[$type])) {
            Response::error('The logo couldn\'t be saved on the server.', 500);
        }
        $url = 'api/public/logo?v=' . time();
        Database::platform()->prepare('UPDATE saccos SET logo_url = ? WHERE code = ?')->execute([$url, $sacco['code']]);
        Tenant::forget();
        Audit::log('settings.logo', null, null, ['size' => $f['size']]);
        Response::ok(['logo_url' => $url]);
    }

    /** DELETE /api/settings/logo */
    public function removeLogo(Request $request): never
    {
        $sacco = Tenant::current();
        foreach (self::LOGO_TYPES as $ext) {
            @unlink(self::LOGO_DIR . "/{$sacco['code']}.$ext");
        }
        Database::platform()->prepare('UPDATE saccos SET logo_url = NULL WHERE code = ?')->execute([$sacco['code']]);
        Tenant::forget();
        Audit::log('settings.logo_removed');
        Response::ok();
    }

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
