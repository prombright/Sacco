<?php

declare(strict_types=1);

use Sacco\Auth\Roles;
use Sacco\Controllers\AuthController;
use Sacco\Controllers\LoanController;
use Sacco\Controllers\PayrollController;
use Sacco\Controllers\WithdrawalController;
use Sacco\Controllers\SettingsController;
use Sacco\Controllers\TransactionController;
use Sacco\Controllers\UserController;
use Sacco\Http\Router;

$router = new Router();

$admins = ['roles' => [Roles::ADMIN]];

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
$router->get('/api/me/summary', [TransactionController::class, 'mySummary']);
$router->get('/api/me/transactions', [TransactionController::class, 'myTransactions']);

// Admins: members' accounts and recording entries
$router->get('/api/members/{id}', [TransactionController::class, 'member'], $admins);
$router->get('/api/members/{id}/transactions', [TransactionController::class, 'memberTransactions'], $admins);
$router->post('/api/members/{id}/transactions', [TransactionController::class, 'store'], $admins);
$router->post('/api/transactions/{id}/reverse', [TransactionController::class, 'reverse'], $admins);

// Loans - members
$router->get('/api/loans/quote', [LoanController::class, 'quote']);
$router->get('/api/me/loans', [LoanController::class, 'mine']);
$router->post('/api/loans', [LoanController::class, 'store']);
$router->get('/api/loans/{id}', [LoanController::class, 'show']);
$router->post('/api/loans/{id}/guarantors', [LoanController::class, 'addGuarantor']);
$router->post('/api/loans/{id}/cancel', [LoanController::class, 'cancel']);
$router->get('/api/members/search', [LoanController::class, 'searchMembers']);
$router->get('/api/me/guarantees', [LoanController::class, 'myGuarantees']);
$router->post('/api/guarantees/{id}/accept', [LoanController::class, 'accept']);
$router->post('/api/guarantees/{id}/decline', [LoanController::class, 'decline']);

// Loans - admins
$router->get('/api/loans', [LoanController::class, 'index'], $admins);
$router->post('/api/loans/{id}/approve', [LoanController::class, 'approve'], $admins);
$router->post('/api/loans/{id}/reject', [LoanController::class, 'reject'], $admins);
$router->post('/api/loans/{id}/disburse', [LoanController::class, 'disburse'], $admins);
$router->post('/api/loans/{id}/payments', [LoanController::class, 'recordPayment'], $admins);
$router->post('/api/loan-payments/{id}/reverse', [LoanController::class, 'reversePayment'], $admins);

// Savings withdrawals (paid by cheque)
$router->get('/api/me/withdrawals', [WithdrawalController::class, 'mine']);
$router->post('/api/withdrawals', [WithdrawalController::class, 'store']);
$router->get('/api/withdrawals/{id}', [WithdrawalController::class, 'show']);
$router->post('/api/withdrawals/{id}/cancel', [WithdrawalController::class, 'cancel']);
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

// The SACCO's rules
$router->get('/api/settings', [SettingsController::class, 'index'], $admins);
$router->patch('/api/settings', [SettingsController::class, 'update'], ['roles' => [Roles::SUPER_ADMIN]]);

return $router;
