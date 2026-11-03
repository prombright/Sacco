<?php

declare(strict_types=1);

use Sacco\Auth\Roles;
use Sacco\Controllers\AuthController;
use Sacco\Controllers\CashbookController;
use Sacco\Controllers\DividendController;
use Sacco\Controllers\MemberDetailsController;
use Sacco\Controllers\MemberExitController;
use Sacco\Controllers\NoticeController;
use Sacco\Controllers\NotificationController;
use Sacco\Controllers\ProductController;
use Sacco\Controllers\LoanController;
use Sacco\Controllers\MemberImportController;
use Sacco\Controllers\OfficeController;
use Sacco\Controllers\ReportController;
use Sacco\Controllers\PayrollController;
use Sacco\Controllers\WithdrawalController;
use Sacco\Controllers\SettingsController;
use Sacco\Controllers\TransactionController;
use Sacco\Controllers\UserController;
use Sacco\Http\Router;

$router = new Router();

$admins = ['roles' => [Roles::ADMIN]];
// A member's own money and profile; office accounts have none
$members = ['members_only' => true];

// Public
$router->get('/api/sacco', [AuthController::class, 'sacco'], ['auth' => false]);
$router->get('/api/auth/me', [AuthController::class, 'me'], ['auth' => false]);
$router->post('/api/auth/login', [AuthController::class, 'login'], ['auth' => false]);

// Signed in, even before the temporary password is changed
$router->post('/api/auth/logout', [AuthController::class, 'logout'], ['before_password' => true]);
$router->post('/api/auth/change-password', [AuthController::class, 'changePassword'], ['before_password' => true]);

// Users (admins manage members; super admins manage everyone)
$router->get('/api/users', [UserController::class, 'index'], $admins);
$router->post('/api/users', [UserController::class, 'store'], $admins);
$router->patch('/api/users/{id}', [UserController::class, 'update'], $admins);
$router->post('/api/users/{id}/reset-password', [UserController::class, 'resetPassword'], $admins);

// A member's own savings and shares
$router->get('/api/me/summary', [TransactionController::class, 'mySummary'], $members);
$router->get('/api/me/transactions', [TransactionController::class, 'myTransactions'], $members);

// Admins: members' accounts and recording entries
$router->get('/api/members/{id}', [TransactionController::class, 'member'], $admins);
$router->get('/api/members/{id}/transactions', [TransactionController::class, 'memberTransactions'], $admins);
$router->post('/api/members/{id}/transactions', [TransactionController::class, 'store'], $admins);
$router->post('/api/transactions/{id}/reverse', [TransactionController::class, 'reverse'], $admins);

// Loans - members
$router->get('/api/loans/quote', [LoanController::class, 'quote'], $members);
$router->get('/api/me/loans', [LoanController::class, 'mine'], $members);
$router->post('/api/loans', [LoanController::class, 'store'], $members);
$router->get('/api/loans/{id}', [LoanController::class, 'show']);
$router->post('/api/loans/{id}/guarantors', [LoanController::class, 'addGuarantor'], $members);
$router->post('/api/loans/{id}/cancel', [LoanController::class, 'cancel']);
$router->get('/api/members/search', [LoanController::class, 'searchMembers']);
$router->get('/api/me/guarantees', [LoanController::class, 'myGuarantees'], $members);
$router->post('/api/guarantees/{id}/accept', [LoanController::class, 'accept'], $members);
$router->post('/api/guarantees/{id}/decline', [LoanController::class, 'decline'], $members);

// Loans - admins
$router->get('/api/loans', [LoanController::class, 'index'], $admins);
$router->post('/api/loans/{id}/approve', [LoanController::class, 'approve'], $admins);
$router->post('/api/loans/{id}/reject', [LoanController::class, 'reject'], $admins);
$router->post('/api/loans/{id}/disburse', [LoanController::class, 'disburse'], $admins);
$router->post('/api/loans/{id}/payments', [LoanController::class, 'recordPayment'], $admins);
$router->get('/api/loans/{id}/payment-preview', [LoanController::class, 'previewPayment'], $admins);
$router->post('/api/loan-payments/{id}/reverse', [LoanController::class, 'reversePayment'], $admins);

// Savings withdrawals (paid by cheque)
$router->get('/api/me/withdrawals', [WithdrawalController::class, 'mine'], $members);
$router->post('/api/withdrawals', [WithdrawalController::class, 'store'], $members);
$router->get('/api/withdrawals/{id}', [WithdrawalController::class, 'show']);
$router->post('/api/withdrawals/{id}/cancel', [WithdrawalController::class, 'cancel'], $members);
$router->get('/api/withdrawals', [WithdrawalController::class, 'index'], $admins);
$router->post('/api/withdrawals/{id}/approve', [WithdrawalController::class, 'approve'], $admins);
$router->post('/api/withdrawals/{id}/reject', [WithdrawalController::class, 'reject'], $admins);
$router->post('/api/withdrawals/{id}/pay', [WithdrawalController::class, 'pay'], $admins);

// Payroll uploads
$router->get('/api/payroll/members', [PayrollController::class, 'members'], $admins);
$router->post('/api/payroll/preview', [PayrollController::class, 'preview'], $admins);
$router->get('/api/payroll/batches', [PayrollController::class, 'index'], $admins);
$router->post('/api/payroll/batches', [PayrollController::class, 'post'], $admins);
$router->get('/api/payroll/batches/{id}', [PayrollController::class, 'show'], $admins);
$router->post('/api/payroll/batches/{id}/reverse', [PayrollController::class, 'reverse'], $admins);

// The office at a glance
$router->get('/api/office/counts', [OfficeController::class, 'counts'], $admins);
$router->get('/api/office/overview', [OfficeController::class, 'overview'], $admins);

// Printable statements
$router->get('/api/me/statement', [TransactionController::class, 'myStatement'], $members);
$router->get('/api/members/{id}/statement', [TransactionController::class, 'memberStatement'], $admins);

// Bringing in many members at once from Excel
$router->post('/api/members/import/preview', [MemberImportController::class, 'preview'], $admins);
$router->post('/api/members/import', [MemberImportController::class, 'import'], $admins);

// Dividends on share capital
$router->post('/api/dividends/preview', [DividendController::class, 'preview'], $admins);
$router->get('/api/dividends', [DividendController::class, 'index'], $admins);
$router->post('/api/dividends', [DividendController::class, 'post'], $admins);
$router->get('/api/dividends/{id}', [DividendController::class, 'show'], $admins);
$router->post('/api/dividends/{id}/reverse', [DividendController::class, 'reverse'], $admins);

// Reports (shown on screen and downloaded as Excel)
$router->get('/api/reports', [ReportController::class, 'index'], $admins);
$router->get('/api/reports/{name}', [ReportController::class, 'show'], $admins);

// Loan products, penalties, early settlement
$router->get('/api/loan-products', [ProductController::class, 'index']);
$router->post('/api/loan-products', [ProductController::class, 'store'], ['roles' => [Roles::SUPER_ADMIN]]);
$router->patch('/api/loan-products/{id}', [ProductController::class, 'update'], ['roles' => [Roles::SUPER_ADMIN]]);
$router->post('/api/loans/penalties/run', [LoanController::class, 'runPenalties'], $admins);
$router->post('/api/loans/existing', [LoanController::class, 'recordExisting'], $admins);
$router->post('/api/loans/{id}/settle', [LoanController::class, 'settle'], $admins);
$router->post('/api/loan-charges/{id}/waive', [LoanController::class, 'waiveCharge'], $admins);

// Member details: next of kin, own profile, leaving the SACCO
$router->get('/api/me/profile', [MemberDetailsController::class, 'myProfile'], $members);
$router->post('/api/me/next-of-kin', [MemberDetailsController::class, 'save'], $members);
$router->get('/api/members/{id}/next-of-kin', [MemberDetailsController::class, 'index'], $admins);
$router->post('/api/members/{id}/next-of-kin', [MemberDetailsController::class, 'save'], $admins);
$router->get('/api/members/{id}/exit', [MemberExitController::class, 'quote'], $admins);
$router->post('/api/members/{id}/exit', [MemberExitController::class, 'close'], $admins);

// The SACCO's own income and expenses
$router->get('/api/cashbook', [CashbookController::class, 'index'], $admins);
$router->post('/api/cashbook', [CashbookController::class, 'store'], $admins);
$router->post('/api/cashbook/{id}/reverse', [CashbookController::class, 'reverse'], $admins);

// Notices to members
$router->get('/api/notices/current', [NoticeController::class, 'current']);
$router->get('/api/notices', [NoticeController::class, 'index'], $admins);
$router->post('/api/notices', [NoticeController::class, 'store'], $admins);
$router->delete('/api/notices/{id}', [NoticeController::class, 'end'], $admins);

// Messages to members (the bell)
$router->get('/api/me/notifications', [NotificationController::class, 'index']);
$router->post('/api/me/notifications/read', [NotificationController::class, 'read']);

// The SACCO's rules
$router->get('/api/settings', [SettingsController::class, 'index'], $admins);
$router->patch('/api/settings', [SettingsController::class, 'update'], ['roles' => [Roles::SUPER_ADMIN]]);

return $router;
