<?php

declare(strict_types=1);

namespace Sacco\Support;

use Sacco\Controllers\SettingsController;

/**
 * Audit rows in words, for the office overview, the developer portal and the messages office
 * accounts get when a super admin changes something.
 */
final class Activity
{
    /** An audit row ({action, details, subject_type, subject_id}) as a short phrase: "approved a loan" */
    public static function describe(array $r): string
    {
        $d = self::details($r);
        $amount = fn (string $k) => isset($d[$k]) && is_numeric($d[$k]) ? number_format((float) $d[$k]) : '';
        $role = match ($d['role'] ?? 'member') {
            'super_admin' => 'a super admin',
            'admin' => 'an admin',
            default => 'a member',
        };
        return match ($r['action']) {
            'loan.applied' => "applied for a loan of {$amount('amount')}",
            'loan.approved' => 'approved a loan',
            'loan.rejected' => 'rejected a loan',
            'loan.cancelled' => 'cancelled a loan application',
            'loan.disbursed' => 'paid out a loan',
            'loan.brought_in' => "brought in a loan of {$amount('amount')} from the old records",
            'loan.payment_recorded' => "recorded a loan repayment of {$amount('amount')}",
            'loan.payment_reversed' => 'reversed a loan repayment',
            'loan.settled_early' => "recorded a loan paid off early ({$amount('amount')})",
            'loan.penalties_charged' => 'charged late-payment penalties on ' . ($d['count'] ?? 0) . ' loans',
            'loan.charge_waived' => 'waived a loan charge',
            'loan.guarantor_added' => 'asked another guarantor',
            'loan.guarantee_accepted' => 'agreed to guarantee a loan',
            'loan.guarantee_declined' => 'declined to guarantee a loan',
            'loan_product.created' => 'added the loan type "' . ($d['name'] ?? '') . '"',
            'loan_product.updated' => 'changed the loan type "' . ($d['after']['name'] ?? $d['before']['name'] ?? '') . '"',
            'withdrawal.requested' => "asked to withdraw {$amount('amount')}",
            'withdrawal.cancelled' => 'cancelled a withdrawal request',
            'withdrawal.approved' => 'approved a withdrawal',
            'withdrawal.rejected' => 'rejected a withdrawal',
            'withdrawal.paid' => 'issued a withdrawal cheque',
            'payroll.posted' => 'posted the payroll for ' . ($d['period'] ?? ''),
            'payroll.reversed' => 'reversed a payroll upload',
            'dividend.posted' => 'credited the ' . ($d['label'] ?? '') . ' dividend',
            'dividend.reversed' => 'reversed a dividend or profit share',
            'profit_share.posted' => 'shared out the ' . ($d['year'] ?? '') . " investment profit ({$amount('pool')})",
            'investment.created' => 'added the investment "' . ($d['name'] ?? '') . "\" ({$amount('amount')})",
            'investment.updated' => 'changed an investment',
            'investment.return' => "recorded an investment return of {$amount('amount')}",
            'investment.return_reversed' => 'reversed an investment return',
            'investment.closed' => 'closed an investment',
            'transaction.recorded' => 'recorded a ' . str_replace('_', ' ', (string) ($d['type'] ?? 'entry')) . " of {$amount('amount')}",
            'transaction.reversed' => 'reversed an entry',
            'cashbook.recorded' => 'recorded SACCO ' . ($d['kind'] ?? 'income or expense') . " of {$amount('amount')}",
            'cashbook.reversed' => 'reversed an income or expense entry',
            'user.created' => "added $role",
            'user.updated' => 'changed an account' . (isset($d['role']) ? "'s role" : (isset($d['status']) ? "'s status" : '')),
            'user.password_reset' => 'reset a password',
            'users.imported' => 'imported ' . ($d['count'] ?? '') . ' members',
            'next_of_kin.saved' => 'updated next of kin',
            'member.exited' => 'closed a member\'s account',
            'notice.posted' => 'posted the notice "' . ($d['title'] ?? '') . '"',
            'notice.ended' => 'took down a notice',
            'settings.changed' => 'changed the SACCO rules',
            'password.changed' => 'changed their password',
            'platform.sacco_updated' => 'changed the SACCO\'s details',
            'platform.office_account_added' => "added $role",
            'platform.password_reset' => 'reset a password',
            'platform.user_status' => 'changed an account\'s status',
            'platform.migrated' => 'updated the system',
            'sacco.created' => 'set up the SACCO',
            'transfer.posted' => "transferred {$amount('amount')} between accounts",
            'transfer.reversed' => 'reversed a transfer',
            'journal.posted' => "posted a journal entry of {$amount('amount')}",
            'journal.reversed' => 'reversed a journal entry',
            'account.created' => 'added the account "' . ($d['name'] ?? '') . '" to the chart of accounts',
            'account.updated' => 'changed an account in the chart of accounts',
            'backup.downloaded' => 'downloaded a backup of the database',
            default => str_replace(['.', '_'], [' ', ' '], $r['action']),
        };
    }

    /** The details worth reading in full: what a rule was and is now, the reason given... */
    public static function explain(array $r): string
    {
        $d = self::details($r);
        $lines = [];
        $show = fn ($v) => $v === null || $v === '' ? '—' : (is_scalar($v) ? (string) $v : json_encode($v));
        if ($r['action'] === 'settings.changed') {
            foreach ($d as $key => $change) {
                $lines[] = SettingsController::label((string) $key) . ': ' . $show($change['from'] ?? null) . ' → ' . $show($change['to'] ?? null);
            }
        } elseif (isset($d['before'], $d['after']) && is_array($d['before']) && is_array($d['after'])) {
            foreach ($d['after'] as $k => $v) {
                $was = $d['before'][$k] ?? null;
                if (is_scalar($v) && (string) $was !== (string) $v && !in_array($k, ['id', 'updated_at', 'created_at'], true)) {
                    $lines[] = ucfirst(str_replace('_', ' ', (string) $k)) . ': ' . $show($was) . ' → ' . $v;
                }
            }
        } else {
            if (isset($d['changes']) && is_array($d['changes'])) {
                foreach ($d['changes'] as $k => $c) {
                    $lines[] = ucfirst(str_replace('_', ' ', (string) $k)) . ': ' . $show($c['from'] ?? null) . ' → ' . $show($c['to'] ?? null);
                }
            }
            foreach (['name' => 'Name', 'role' => 'Role', 'status' => 'Status', 'reason' => 'Reason', 'note' => 'Note', 'period' => 'Period', 'rate' => 'Rate %'] as $k => $label) {
                if (isset($d[$k]) && is_scalar($d[$k])) {
                    $lines[] = "$label: " . $d[$k];
                }
            }
        }
        return implode("\n", $lines);
    }

    /** Where in the office app the change can be seen */
    public static function link(array $r): ?string
    {
        $d = self::details($r);
        $id = $r['subject_id'] ?? null;
        return match ($r['subject_type'] ?? null) {
            'loan' => "/loans/$id",
            'user' => $r['action'] === 'user.created' || str_starts_with($r['action'], 'platform.') ? '/users' : "/members/$id",
            'dividend_run' => "/admin/dividends/$id",
            'payroll_batch' => "/admin/payroll/$id",
            'investment' => '/admin/investments',
            'loan_product' => '/admin/settings',
            'withdrawal' => '/admin/withdrawals',
            'cashbook' => '/cashbook/payments',
            'notice' => '/admin/notices',
            'transfer' => '/admin/transfers',
            'journal' => '/accounts/journal',
            'gl_account' => '/accounts/chart',
            'transaction' => isset($d['member']) ? '/members/' . $d['member'] : null,
            default => match (true) {
                $r['action'] === 'settings.changed' => '/admin/settings',
                str_starts_with($r['action'], 'loan.') => '/admin/loans',
                $r['action'] === 'users.imported' => '/users',
                default => null,
            },
        };
    }

    private static function details(array $r): array
    {
        $d = $r['details'] ?? null;
        if (is_string($d)) {
            $d = json_decode($d, true);
        }
        return is_array($d) ? $d : [];
    }
}
