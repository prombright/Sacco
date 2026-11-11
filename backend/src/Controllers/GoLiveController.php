<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Support\Audit;
use Sacco\Support\Books;
use Sacco\Support\Money;
use Sacco\Support\Settings;

/**
 * The super admin's checklist for switching over from the old system: each step shows whether it
 * is done, worked out from the records where it can be, and ticked by hand where only a person
 * knows (the old system is no longer used, the slips were handed out).
 */
final class GoLiveController
{
    /** Steps only a person can confirm */
    private const MANUAL = ['caught_up', 'old_system_stopped', 'slips', 'passwords', 'review'];

    /** GET /api/go-live */
    public function show(Request $request): never
    {
        Response::ok($this->checklist());
    }

    /** POST /api/go-live/{step} { done } - tick or untick a step only a person can confirm */
    public function tick(Request $request): never
    {
        $step = (string) $request->param('step');
        if (!in_array($step, self::MANUAL, true)) {
            Response::error('That step is worked out by the system.', 422);
        }
        $done = (bool) $request->input('done', true);
        Database::tenant()->prepare("INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
            ->execute(["go_live_$step", $done ? date('Y-m-d') : '']);
        Audit::log($done ? 'go_live.ticked' : 'go_live.unticked', null, null, ['step' => $step]);
        Response::ok($this->checklist());
    }

    private function checklist(): array
    {
        $db = Database::tenant();
        $one = function (string $sql, array $args = []) use ($db) {
            $s = $db->prepare($sql);
            $s->execute($args);
            return $s->fetchColumn();
        };
        $ticked = fn (string $k) => (string) Settings::get("go_live_$k", '');
        $money = fn (int $c) => 'UGX ' . number_format($c / 100);
        $day = fn (?string $d) => $d ? date('j M Y', strtotime($d)) : null;

        // The old system's balance date, from what was brought in
        $balanceDate = $one("SELECT MIN(disbursed_on) FROM loans WHERE source = 'old_records' AND disbursement_ref = 'Old system'")
            ?: $one('SELECT MIN(as_at) FROM old_system_interest_payable') ?: null;
        $imported = $one("SELECT MAX(created_at) FROM audit_log WHERE action LIKE 'old_system.%' OR action = 'users.imported'") ?: null;

        // 1. The books balance
        Books::sync($db);
        $dr = $cr = 0;
        foreach (Books::totals($db, date('Y-m-d')) as $t) {
            $dr += $t['d'];
            $cr += $t['c'];
        }
        // 2. A backup since the import
        $backup = $one("SELECT MAX(created_at) FROM audit_log WHERE action = 'backup.downloaded'") ?: null;
        // 3. Every month since the balance date has its entries
        $months = [];
        if ($balanceDate) {
            // From the first of the month after (31 Aug + 1 month would skip September)
            $m = new \DateTime(date('Y-m-01', strtotime(date('Y-m-01', strtotime($balanceDate)) . ' +1 month')));
            $end = new \DateTime(date('Y-m-01'));
            while ($m <= $end) {
                $from = $m->format('Y-m-01');
                $count = (int) $one('SELECT COUNT(*) FROM transactions WHERE value_date BETWEEN ? AND LAST_DAY(?)', [$from, $from])
                    + (int) $one('SELECT COUNT(*) FROM loan_payments WHERE paid_on BETWEEN ? AND LAST_DAY(?)', [$from, $from]);
                $payroll = (bool) $one("SELECT 1 FROM payroll_batches WHERE kind = 'payroll' AND status = 'posted' AND period = ?", [$m->format('Y-m')]);
                $months[] = ['month' => $m->format('Y-m'), 'label' => $m->format('F Y'), 'entries' => $count, 'payroll' => $payroll];
                $m->modify('+1 month');
            }
        }
        $missing = array_values(array_filter($months, fn ($x) => !$x['payroll'] && $x['month'] < date('Y-m')));
        // 6. Members signing in
        $members = (int) $one("SELECT COUNT(*) FROM users WHERE role = 'member' AND status = 'active'");
        $signedIn = (int) $one("SELECT COUNT(*) FROM users WHERE role = 'member' AND status = 'active' AND last_login_at IS NOT NULL");
        // 7. The super admin can be reached by email
        $noEmail = (int) $one("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND status = 'active' AND (email IS NULL OR email = '')");
        // 9. What the old books left to sort out
        $balanceOf = function (string $key) use ($db): int {
            $id = Books::account($db, $key);
            $s = $db->prepare('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE l.account_id = ?');
            $s->execute([$id]);
            return Money::fromDecimal((string) $s->fetchColumn());
        };
        $suspense = $balanceOf('suspense');
        $transit = $balanceOf('funds_in_transit');
        // 10. The committee's answers
        $questions = (int) $one('SELECT COUNT(*) FROM committee_questions');
        $decided = (int) $one("SELECT COUNT(*) FROM committee_questions WHERE status = 'decided'");

        $steps = [
            ['key' => 'balanced', 'title' => 'The books balance', 'done' => $dr === $cr,
                'detail' => $dr === $cr ? 'Debits and credits are equal (' . $money($dr) . ').' : 'Debits ' . $money($dr) . ', credits ' . $money($cr) . '. Ask the developer before going on.',
                'link' => ['label' => 'Trial balance', 'to' => '/accounts/trial-balance']],
            ['key' => 'backup', 'title' => 'Download a backup now everything is in', 'done' => $backup && (!$imported || $backup >= $imported),
                'detail' => $backup ? 'Last backup: ' . $day($backup) . ($imported && $backup < $imported ? ', before the import. Take a new one.' : '.') : 'No backup downloaded yet.',
                'link' => ['label' => 'Download backup', 'to' => '/admin/backup']],
            ['key' => 'caught_up', 'title' => 'Enter everything since the balance date', 'done' => $ticked('caught_up') !== '',
                'detail' => $balanceDate
                    ? 'The old records end on ' . $day($balanceDate) . '. ' . ($missing ? 'No payroll posted yet for ' . implode(', ', array_column($missing, 'label')) . '. ' : 'Every month since has a payroll. ')
                        . 'Use a quiet payroll for each month (dated in its month), and Import transactions for anything else. Tick when the old system has nothing more.'
                    : 'Bring in the old system first.',
                'months' => $months, 'manual' => true,
                'link' => ['label' => 'Import transactions', 'to' => '/admin/transaction-import']],
            ['key' => 'reminders', 'title' => 'Turn loan reminders back on', 'done' => Settings::get('loan_reminders', 'on') !== 'off',
                'detail' => Settings::get('loan_reminders', 'on') === 'off' ? 'Paused. Turn them on once the loans are up to date, or borrowers are told they are late for payments already made.' : 'On: members are reminded before instalments fall due.',
                'link' => ['label' => 'Settings', 'to' => '/admin/settings']],
            ['key' => 'old_system_stopped', 'title' => 'Stop using the old system', 'done' => $ticked('old_system_stopped') !== '',
                'detail' => 'From the day everything is entered, record new money only here, so the two never drift apart.', 'manual' => true],
            ['key' => 'slips', 'title' => 'Hand out the sign-in slips', 'done' => $ticked('slips') !== '',
                'detail' => "$signedIn of $members members have signed in. Members use SM and their old code (e.g. SM004) and choose their own password.", 'manual' => true,
                'link' => ['label' => 'Members', 'to' => '/users']],
            ['key' => 'email', 'title' => 'Give the super admin an email address', 'done' => $noEmail === 0,
                'detail' => $noEmail ? 'Needed for password resets and copies of office messages. Add it on your profile.' : 'Every super admin has an email address.',
                'link' => ['label' => 'My profile', 'to' => '/profile']],
            ['key' => 'passwords', 'title' => 'Change the super admin and developer passwords', 'done' => $ticked('passwords') !== '',
                'detail' => 'The starting passwords were shared while setting up. Change them from the menu (Change password).', 'manual' => true],
            ['key' => 'review', 'title' => 'Sort out what the old books left with the accountant', 'done' => $ticked('review') !== '' || ($suspense === 0 && $transit === 0),
                'detail' => 'Suspense ' . $money(abs($suspense)) . ', Funds in transit ' . $money(abs($transit)) . '. Each item on the "To review" sheet is cleared with a journal; tick when you are both satisfied.', 'manual' => true,
                'link' => ['label' => 'Journal', 'to' => '/accounts/journal']],
            ['key' => 'committee', 'title' => 'Record the committee\'s decisions', 'done' => $questions > 0 && $decided === $questions,
                'detail' => "$decided of $questions questions decided.", 'link' => ['label' => 'Committee decisions', 'to' => '/admin/committee']],
        ];
        foreach ($steps as $i => &$s) {
            $s['n'] = $i + 1;
            $s['manual'] ??= false;
            $s['ticked_on'] = in_array($s['key'], self::MANUAL, true) ? ($ticked($s['key']) ?: null) : null;
        }
        unset($s);
        return ['steps' => $steps, 'done' => count(array_filter($steps, fn ($s) => $s['done'])), 'total' => count($steps), 'balance_date' => $balanceDate, 'by' => Auth::id()];
    }
}
