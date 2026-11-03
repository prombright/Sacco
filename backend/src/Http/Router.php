<?php

declare(strict_types=1);

namespace Sacco\Http;

use Sacco\Auth\Auth;
use Sacco\Auth\DevAuth;
use Sacco\Auth\Roles;
use Sacco\Config;
use Sacco\Support\LedgerException;

/**
 * Maps METHOD /api/path/{param} to [Controller::class, 'method']. {id} matches digits only.
 *
 * Route options:
 * - 'auth' => false        public route (default: signed in)
 * - 'roles' => [...]       only these roles (a super admin always passes, see Roles::allows)
 * - 'before_password' => true   allowed while the user still has to change their temporary password
 * - 'members_only' => true      a member's own money; office accounts (admins, super admin) are refused
 * - 'dev' => true          the developer portal: signed in as a developer, not a SACCO user
 *
 * Signed-in POST/PATCH/PUT/DELETE requests must carry the session's X-CSRF-Token header.
 */
final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler, array $options = []): void
    {
        $this->add('GET', $path, $handler, $options);
    }

    public function post(string $path, array $handler, array $options = []): void
    {
        $this->add('POST', $path, $handler, $options);
    }

    public function patch(string $path, array $handler, array $options = []): void
    {
        $this->add('PATCH', $path, $handler, $options);
    }

    public function delete(string $path, array $handler, array $options = []): void
    {
        $this->add('DELETE', $path, $handler, $options);
    }

    private function add(string $method, string $path, array $handler, array $options): void
    {
        // {id} only matches numbers, so /api/members/search never lands on /api/members/{id}
        $regex = '#^' . preg_replace(['#\{id\}#', '#\{([a-z_]+)\}#'], ['(?P<id>\d+)', '(?P<$1>[^/]+)'], $path) . '$#';
        $this->routes[] = compact('method', 'regex', 'handler', 'options');
    }

    public function dispatch(Request $request): never
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $this->guard($request, $route['options']);
            [$class, $method] = $route['handler'];
            try {
                (new $class())->$method($request);
            } catch (LedgerException $e) {
                // A money rule said no; the message is written for the person who tried
                Response::error($e->getMessage(), 422);
            } catch (\Throwable $e) {
                error_log('[sacco] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                Response::error(Config::isDebug() ? $e->getMessage() : 'Something went wrong. Please try again.', 500);
            }
            Response::ok();
        }
        Response::error($pathMatched ? 'Method not allowed' : 'Not found', $pathMatched ? 405 : 404);
    }

    private function guard(Request $request, array $options): void
    {
        if (($options['auth'] ?? true) === false) {
            return;
        }
        if (!empty($options['dev'])) {
            $this->guardDeveloper($request, $options);
            return;
        }
        $user = Auth::user();
        if (!$user) {
            Response::error('Please sign in.', 401);
        }
        if ($request->method !== 'GET' && !hash_equals(Auth::csrfToken(), $request->header('X-CSRF-Token'))) {
            Response::error('Your session has expired. Please refresh the page.', 419);
        }
        if ($user['must_change_password'] && empty($options['before_password'])) {
            Response::error('Please change your temporary password first.', 403, ['code' => 'must_change_password']);
        }
        if (isset($options['roles']) && !Roles::allows($user['role'], $options['roles'])) {
            Response::error('You do not have permission to do that.', 403);
        }
        // A member's own money: office accounts (admins, super admin) have none of their own
        if (!empty($options['members_only']) && $user['role'] !== Roles::MEMBER) {
            Response::error('This is an office account, which has no savings or loans. Sign in with your member account for that.', 403);
        }
    }

    private function guardDeveloper(Request $request, array $options): void
    {
        $developer = DevAuth::user();
        if (!$developer) {
            Response::error('Please sign in to the developer portal.', 401);
        }
        if ($request->method !== 'GET' && !hash_equals(Auth::csrfToken(), $request->header('X-CSRF-Token'))) {
            Response::error('Your session has expired. Please refresh the page.', 419);
        }
        if ($developer['must_change_password'] && empty($options['before_password'])) {
            Response::error('Please change your temporary password first.', 403, ['code' => 'must_change_password']);
        }
    }
}
