<?php

declare(strict_types=1);

use Sacco\Auth\Roles;
use Sacco\Controllers\AuthController;
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

return $router;
