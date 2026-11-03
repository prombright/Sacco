<?php

declare(strict_types=1);

/**
 * The API's single entry point: every /api/... request comes here (see .htaccess).
 */

require __DIR__ . '/../bootstrap.php';

use Sacco\Auth\Auth;
use Sacco\Config;
use Sacco\Http\Request;

ini_set('display_errors', Config::isDebug() ? '1' : '0');
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$request = Request::capture();
Auth::startSession(str_starts_with($request->path, '/api/dev/') ? 'sacco_dev_session' : 'sacco_session');

$router = require __DIR__ . '/../routes.php';
$router->dispatch($request);
