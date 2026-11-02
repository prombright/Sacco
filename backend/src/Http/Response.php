<?php

declare(strict_types=1);

namespace Sacco\Http;

/**
 * Every API reply is JSON shaped { success, data } or { success: false, message, errors? }.
 */
final class Response
{
    public static function ok(mixed $data = null, int $status = 200): never
    {
        self::send($status, ['success' => true, 'data' => $data]);
    }

    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        $body = ['success' => false, 'message' => $message];
        if ($errors) {
            $body['errors'] = $errors;
        }
        self::send($status, $body);
    }

    private static function send(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
