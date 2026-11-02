<?php

declare(strict_types=1);

use Sacco\Auth\Roles;
use Sacco\Controllers\AuthController;
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

return $router;
