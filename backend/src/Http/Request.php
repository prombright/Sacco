<?php

declare(strict_types=1);

namespace Sacco\Http;

final class Request
{
    private ?array $json = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public array $params = [],
    ) {
    }

    public static function capture(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Every route lives under /api, wherever the app is installed (/api, /sacco/api,
        // /sacco/backend/public/api...), so anchor on that segment
        $at = strpos($uri, '/api/');
        $path = $at === false ? $uri : substr($uri, $at);
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), '/' . trim($path, '/'));
    }

    /** A value from the JSON body, else the query string */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->json()[$key] ?? $_GET[$key] ?? $default;
    }

    public function string(string $key): string
    {
        $value = $this->input($key, '');
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public function param(string $key): string
    {
        return (string) ($this->params[$key] ?? '');
    }

    public function json(): array
    {
        if ($this->json === null) {
            $body = file_get_contents('php://input') ?: '';
            $decoded = json_decode($body, true);
            $this->json = is_array($decoded) ? $decoded : [];
        }
        return $this->json;
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER[$key] ?? '');
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
