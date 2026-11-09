<?php

declare(strict_types=1);

use Sacco\Auth\Roles;
use Sacco\Controllers\AccountingController;
use Sacco\Controllers\AuthController;
use Sacco\Controllers\BankRecController;
use Sacco\Controllers\BackupController;
use Sacco\Controllers\CashbookController;
use Sacco\Controllers\DevController;
use Sacco\Controllers\DividendController;
use Sacco\Controllers\FinanceController;
use Sacco\Controllers\InvestmentController;
use Sacco\Controllers\MemberDetailsController;
use Sacco\Controllers\MemberExitController;
use Sacco\Controllers\NoticeController;
use Sacco\Controllers\NotificationController;
use Sacco\Controllers\ProductController;
use Sacco\Controllers\PublicController;
use Sacco\Controllers\LoanController;
use Sacco\Controllers\MemberImportController;
use Sacco\Controllers\MembershipFeeController;
use Sacco\Controllers\OfficeController;
use Sacco\Controllers\ReceiptController;
use Sacco\Controllers\ReportController;
use Sacco\Controllers\PayrollController;
use Sacco\Controllers\WithdrawalController;
use Sacco\Controllers\SettingsController;
use Sacco\Controllers\TransactionController;
use Sacco\Controllers\TransferController;
use Sacco\Controllers\TwoStepController;
use Sacco\Controllers\UsageController;
use Sacco\Controllers\UserController;
use Sacco\Http\Router;

$router = new Router();

$admins = ['roles' => [Roles::ADMIN]];
// Changes: the super admin, or admins the super admin allowed in that area
$edit = fn (string $area) => ['roles' => [Roles::ADMIN], 'can' => $area];
// A member's own money and profile; office accounts have none
$members = ['members_only' => true];

// Public
$router->get('/api/sacco', [AuthController::class, 'sacco'], ['auth' => false]);
$router->get('/api/auth/me', [AuthController::class, 'me'], ['auth' => false]);
$router->post('/api/auth/login', [AuthController::class, 'login'], ['auth' => false]);
$router->get('/api/public/terms', [PublicController::class, 'terms'], ['auth' => false]);
$router->get('/api/public/manifest', [PublicController::class, 'manifest'], ['auth' => false]);
$router->get('/api/public/logo', [PublicController::class, 'logo'], ['auth' => false]);

$router->post('/api/auth/two-step', [AuthController::class, 'twoStep'], ['auth' => false]);

// Signed in, even before the temporary password is changed
$router->post('/api/auth/logout', [AuthController::class, 'logout'], ['before_password' => true]);
$router->post('/api/auth/change-password', [AuthController::class, 'changePassword'], ['before_password' => true]);

// Users (admins manage members; super admins manage everyone)
$router->get('/api/users', [UserController::class, 'index'], $admins);
$router->post('/api/users', [UserController::class, 'store'], $edit('members'));
$router->patch('/api/users/{id}', [UserController::class, 'update'], $edit('members'));
$router->post('/api/users/{id}/reset-password', [UserController::class, 'resetPassword'], $edit('members'));

// A member's own savings and shares
$router->get('/api/me/summary', [TransactionController::class, 'mySummary'], $members);
$router->get('/api/me/transactions', [TransactionController::class, 'myTransactions'], $members);

// Admins: members' accounts and recording entries
$router->get('/api/members/{id}', [TransactionController::class, 'member'], $admins);
$router->get('/api/members/{id}/transactions', [TransactionController::class, 'memberTransactions'], $admins);
$router->post('/api/members/{id}/transactions', [TransactionController::class, 'store'], $edit('money'));
$router->post('/api/transactions/{id}/reverse', [TransactionController::class, 'reverse'], $edit('money'));

// Loans - members
$router->get('/api/loans/quote', [LoanController::class, 'quote'], $members);
$router->get('/api/me/loans', [LoanController::class, 'mine'], $members);
$router->post('/api/loans', [LoanController::class, 'store'], $members);
$router->get('/api/loans/{id}', [LoanController::class, 'show']);
$router->post('/api/loans/{id}/guarantors', [LoanController::class, 'addGuarantor'], $members);
$router->post('/api/loans/{id}/guarantors/{gid}/replace', [LoanController::class, 'replaceGuarantor']);
$router->post('/api/loans/{id}/cancel', [LoanController::class, 'cancel']);
$router->get('/api/members/search', [LoanController::class, 'searchMembers']);
$router->get('/api/me/guarantees', [LoanController::class, 'myGuarantees'], $members);
$router->post('/api/guarantees/{id}/accept', [LoanController::class, 'accept'], $members);
$router->post('/api/guarantees/{id}/decline', [LoanController::class, 'decline'], $members);

// Loans - admins
$router->get('/api/loans', [LoanController::class, 'index'], $admins);
$router->post('/api/loans/{id}/approve', [LoanController::class, 'approve'], $edit('loans'));
$router->post('/api/loans/{id}/reject', [LoanController::class, 'reject'], $edit('loans'));
$router->post('/api/loans/{id}/disburse', [LoanController::class, 'disburse'], $edit('loans'));
$router->post('/api/loans/{id}/payments', [LoanController::class, 'recordPayment'], $edit('loans'));
$router->get('/api/loans/{id}/payment-preview', [LoanController::class, 'previewPayment'], $admins);
$router->post('/api/loan-payments/{id}/reverse', [LoanController::class, 'reversePayment'], $edit('loans'));

// Savings withdrawals (paid by cheque)
$router->get('/api/me/withdrawals', [WithdrawalController::class, 'mine'], $members);
$router->post('/api/withdrawals', [WithdrawalController::class, 'store'], $members);
$router->get('/api/withdrawals/{id}', [WithdrawalController::class, 'show']);
$router->post('/api/withdrawals/{id}/cancel', [WithdrawalController::class, 'cancel'], $members);
$router->get('/api/withdrawals', [WithdrawalController::class, 'index'], $admins);
$router->post('/api/withdrawals/{id}/approve', [WithdrawalController::class, 'approve'], $edit('money'));
$router->post('/api/withdrawals/{id}/reject', [WithdrawalController::class, 'reject'], $edit('money'));
$router->post('/api/withdrawals/{id}/pay', [WithdrawalController::class, 'pay'], $edit('money'));

// Payroll uploads
$router->get('/api/payroll/members', [PayrollController::class, 'members'], $admins);
$router->post('/api/payroll/preview', [PayrollController::class, 'preview'], $admins);
$router->get('/api/payroll/batches', [PayrollController::class, 'index'], $admins);
$router->post('/api/payroll/batches', [PayrollController::class, 'post'], $edit('money'));
$router->get('/api/payroll/batches/{id}', [PayrollController::class, 'show'], $admins);
$router->post('/api/payroll/batches/{id}/reverse', [PayrollController::class, 'reverse'], $edit('money'));

// The office at a glance
$router->get('/api/office/counts', [OfficeController::class, 'counts'], $admins);
$router->get('/api/office/overview', [OfficeController::class, 'overview'], $admins);

// Printable statements
$router->get('/api/me/statement', [TransactionController::class, 'myStatement'], $members);
$router->get('/api/members/{id}/statement', [TransactionController::class, 'memberStatement'], $admins);

// Bringing in many members at once from Excel
$router->post('/api/members/import/preview', [MemberImportController::class, 'preview'], $admins);
$router->post('/api/members/import', [MemberImportController::class, 'import'], $edit('members'));

// Dividends on share capital; payouts are made by the super admin
$super = ['roles' => [Roles::SUPER_ADMIN]];
$router->post('/api/dividends/preview', [DividendController::class, 'preview'], $admins);
$router->get('/api/dividends', [DividendController::class, 'index'], $admins);
$router->post('/api/dividends', [DividendController::class, 'post'], $super);
$router->get('/api/dividends/{id}', [DividendController::class, 'show'], $admins);
$router->post('/api/dividends/{id}/reverse', [DividendController::class, 'reverse'], $super);

// Investments and sharing their profit (set up by the super admin; admins can see)
$router->get('/api/investments', [InvestmentController::class, 'index'], $admins);
$router->post('/api/investments', [InvestmentController::class, 'store'], $super);
$router->patch('/api/investments/{id}', [InvestmentController::class, 'update'], $super);
$router->post('/api/investments/{id}/returns', [InvestmentController::class, 'addReturn'], $super);
$router->post('/api/investments/{id}/close', [InvestmentController::class, 'close'], $super);
$router->post('/api/investment-returns/{id}/reverse', [InvestmentController::class, 'reverseReturn'], $super);
$router->post('/api/profit-share/preview', [InvestmentController::class, 'previewShare'], $admins);
$router->post('/api/profit-share', [InvestmentController::class, 'postShare'], $super);
$router->get('/api/me/earnings', [InvestmentController::class, 'myEarnings'], $members);

// Reports (shown on screen and downloaded as Excel)
$router->get('/api/reports', [ReportController::class, 'index'], $admins);
$router->get('/api/reports/{name}', [ReportController::class, 'show'], $admins);

// Loan products, penalties, early settlement
$router->get('/api/loan-products', [ProductController::class, 'index']);
$router->post('/api/loan-products', [ProductController::class, 'store'], ['roles' => [Roles::SUPER_ADMIN]]);
$router->patch('/api/loan-products/{id}', [ProductController::class, 'update'], ['roles' => [Roles::SUPER_ADMIN]]);
$router->post('/api/loans/penalties/run', [LoanController::class, 'runPenalties'], $edit('loans'));
$router->post('/api/loans/existing', [LoanController::class, 'recordExisting'], $edit('loans'));
$router->post('/api/loans/{id}/settle', [LoanController::class, 'settle'], $edit('loans'));
$router->post('/api/loan-charges/{id}/waive', [LoanController::class, 'waiveCharge'], $edit('loans'));

// Member details: next of kin, own profile, leaving the SACCO
$router->get('/api/me/profile', [MemberDetailsController::class, 'myProfile'], $members);
$router->post('/api/me/next-of-kin', [MemberDetailsController::class, 'save'], $members);
$router->get('/api/members/{id}/next-of-kin', [MemberDetailsController::class, 'index'], $admins);
$router->post('/api/members/{id}/next-of-kin', [MemberDetailsController::class, 'save'], $edit('members'));
$router->get('/api/members/{id}/exit', [MemberExitController::class, 'quote'], $admins);
$router->post('/api/members/{id}/exit', [MemberExitController::class, 'close'], $edit('members'));

// The SACCO's own income and expenses
$router->get('/api/cashbook', [CashbookController::class, 'index'], $admins);
$router->post('/api/cashbook', [CashbookController::class, 'store'], $edit('money'));
$router->post('/api/cashbook/{id}/reverse', [CashbookController::class, 'reverse'], $edit('money'));

// Notices to members
$router->get('/api/notices/current', [NoticeController::class, 'current']);
$router->get('/api/notices', [NoticeController::class, 'index'], $admins);
$router->post('/api/notices', [NoticeController::class, 'store'], $edit('notices'));
$router->delete('/api/notices/{id}', [NoticeController::class, 'end'], $edit('notices'));

// Messages to members (the bell)
$router->get('/api/me/notifications', [NotificationController::class, 'index']);
$router->post('/api/me/notifications/read', [NotificationController::class, 'read']);

// The developer portal: every SACCO on this installation
$dev = ['dev' => true];
$router->get('/api/dev/me', [DevController::class, 'me'], ['auth' => false]);
$router->post('/api/dev/login', [DevController::class, 'login'], ['auth' => false]);
$router->post('/api/dev/logout', [DevController::class, 'logout'], $dev + ['before_password' => true]);
$router->post('/api/dev/change-password', [DevController::class, 'changePassword'], $dev + ['before_password' => true]);
$router->get('/api/dev/overview', [DevController::class, 'overview'], $dev);
$router->get('/api/dev/log', [DevController::class, 'log'], $dev);
$router->post('/api/dev/saccos', [DevController::class, 'create'], $dev);
$router->get('/api/dev/saccos/{id}', [DevController::class, 'show'], $dev);
$router->patch('/api/dev/saccos/{id}', [DevController::class, 'update'], $dev);
$router->get('/api/dev/saccos/{id}/members', [DevController::class, 'members'], $dev);
$router->get('/api/dev/saccos/{id}/activity', [DevController::class, 'activity'], $dev);
$router->post('/api/dev/saccos/{id}/office-accounts', [DevController::class, 'addOfficeAccount'], $dev);
$router->post('/api/dev/saccos/{id}/users/{user}/reset-password', [DevController::class, 'resetPassword'], $dev);
$router->post('/api/dev/saccos/{id}/users/{user}/status', [DevController::class, 'setUserStatus'], $dev);
$router->post('/api/dev/saccos/{id}/update-system', [DevController::class, 'updateSystem'], $dev);

// The books: chart of accounts, journal entries, statements
$router->get('/api/accounting/accounts', [AccountingController::class, 'accounts'], $admins);
$router->get('/api/money-accounts', [AccountingController::class, 'moneyAccounts'], $admins);
$router->post('/api/accounting/accounts', [AccountingController::class, 'createAccount'], $super);
$router->patch('/api/accounting/accounts/{id}', [AccountingController::class, 'updateAccount'], $super);
$router->get('/api/accounting/journal', [AccountingController::class, 'journal'], $admins);
$router->post('/api/accounting/journal', [AccountingController::class, 'createJournal'], $edit('money'));
$router->post('/api/accounting/journal/{id}/reverse', [AccountingController::class, 'reverseJournal'], $edit('money'));
$router->post('/api/accounting/rebuild', [AccountingController::class, 'rebuild'], $super);
$router->get('/api/accounting/reports/{name}', [AccountingController::class, 'report'], $admins);
$router->get('/api/accounting/annual', [AccountingController::class, 'annual'], $admins);

// Internal transfers, every transaction, and backups
$router->get('/api/transfers', [TransferController::class, 'index'], $admins);
$router->post('/api/transfers', [TransferController::class, 'store'], $edit('money'));
$router->post('/api/transfers/{id}/reverse', [TransferController::class, 'reverse'], $edit('money'));
$router->get('/api/transactions', [TransactionController::class, 'all'], $admins);
$router->get('/api/backup', [BackupController::class, 'download'], $super);

// The yearly membership fee
$router->get('/api/membership-fees', [MembershipFeeController::class, 'index'], $admins);
$router->post('/api/membership-fees/charge', [MembershipFeeController::class, 'charge'], $edit('money'));
$router->post('/api/membership-fees/collect', [MembershipFeeController::class, 'collect'], $edit('money'));
$router->post('/api/membership-fees/{id}/pay', [MembershipFeeController::class, 'pay'], $edit('money'));
$router->post('/api/membership-fees/{id}/waive', [MembershipFeeController::class, 'waive'], $edit('money'));
$router->post('/api/membership-fees/{id}/undo', [MembershipFeeController::class, 'undo'], $edit('money'));
$router->get('/api/me/membership-fees', [MembershipFeeController::class, 'mine'], $members);
$router->get('/api/office/sections', [OfficeController::class, 'sections'], $admins);

// Bank reconciliation
$router->get('/api/bank-recs', [BankRecController::class, 'index'], $admins);
$router->post('/api/bank-recs', [BankRecController::class, 'store'], $edit('money'));
$router->get('/api/bank-recs/{id}', [BankRecController::class, 'show'], $admins);
$router->patch('/api/bank-recs/{id}', [BankRecController::class, 'update'], $edit('money'));
$router->post('/api/bank-recs/{id}/tick', [BankRecController::class, 'tick'], $edit('money'));
$router->post('/api/bank-recs/{id}/complete', [BankRecController::class, 'complete'], $edit('money'));
$router->post('/api/bank-recs/{id}/reopen', [BankRecController::class, 'reopen'], $super);
$router->delete('/api/bank-recs/{id}', [BankRecController::class, 'destroy'], $edit('money'));

// Receipts
$router->get('/api/me/receipts', [ReceiptController::class, 'mine'], $members);
$router->get('/api/members/{id}/receipts', [ReceiptController::class, 'member'], $admins);
$router->get('/api/receipts/{no}', [ReceiptController::class, 'show']);

// Usage, and two-step sign-in for your own account
$router->get('/api/office/usage', [UsageController::class, 'index'], $admins);
$router->post('/api/users/{id}/unlock', [UsageController::class, 'unlock'], $edit('members'));
$router->get('/api/me/two-step', [TwoStepController::class, 'status']);
$router->post('/api/me/two-step/start', [TwoStepController::class, 'start']);
$router->post('/api/me/two-step/confirm', [TwoStepController::class, 'confirm']);
$router->post('/api/me/two-step/disable', [TwoStepController::class, 'disable']);

// Year-end closing and budgets
$router->get('/api/finance/years', [FinanceController::class, 'years'], $admins);
$router->post('/api/finance/years/{year}/close', [FinanceController::class, 'close'], $super);
$router->post('/api/finance/years/{year}/reopen', [FinanceController::class, 'reopen'], $super);
$router->get('/api/finance/budget', [FinanceController::class, 'budget'], $admins);
$router->patch('/api/finance/budget', [FinanceController::class, 'saveBudget'], $super);

// The SACCO's rules
$router->get('/api/settings', [SettingsController::class, 'index'], $admins);
$router->patch('/api/settings', [SettingsController::class, 'update'], ['roles' => [Roles::SUPER_ADMIN]]);
$router->post('/api/settings/logo', [PublicController::class, 'uploadLogo'], $super);
$router->delete('/api/settings/logo', [PublicController::class, 'removeLogo'], $super);

return $router;
