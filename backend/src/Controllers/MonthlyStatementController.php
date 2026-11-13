<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Loans\Statements;
use Sacco\Support\Audit;
use Sacco\Support\Money;
use Sacco\Support\Settings;

/** The office's view of every member's monthly statement, to print, or to send now */
final class MonthlyStatementController
{
    /** GET /api/statements/monthly?month=YYYY-MM (default: last month) */
    public function index(Request $request): never
    {
        $month = $this->month($request);
        $money = ['savings_end', 'savings_in', 'savings_out', 'shares_end', 'repaid', 'loan_left', 'next_due_amount'];
        $rows = array_map(function (array $m) use ($money) {
            foreach ($money as $k) {
                $m[$k] = Money::toDecimal($m[$k]);
            }
            return $m;
        }, Statements::summaries(Database::tenant(), $month));
        Response::ok([
            'month' => $month,
            'members' => $rows,
            'automatic' => Settings::get('monthly_statements', 'off') === 'on',
            'sent_for' => Settings::get('statements_sent_for', '') ?: null,
        ]);
    }

    /** POST /api/statements/monthly/send { month } - message every active member now (super admin) */
    public function send(Request $request): never
    {
        $month = $this->month($request);
        @set_time_limit(300);
        $sent = Statements::send(Database::tenant(), $month);
        Audit::log('statements.sent', null, null, ['month' => $month, 'count' => $sent]);
        Response::ok(['sent' => $sent]);
    }

    private function month(Request $request): string
    {
        $month = (string) ($request->query('month') ?: $request->input('month') ?: date('Y-m', strtotime(date('Y-m-01') . ' -1 month')));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month > date('Y-m')) {
            Response::error('Choose a month that has started.', 422);
        }
        return $month;
    }
}
