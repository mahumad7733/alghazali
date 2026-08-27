<?php
declare(strict_types=1);

namespace App\Includes;

final class Response
{
    /** @param array<string, mixed>|list<mixed>|null $data */
    public static function success(string $message, array|null $data = null, int $status = 200): never
    {
        self::send([
            'success' => true,
            'message' => $message,
            'data' => $data ?? [],
        ], $status);
    }

    /** @param array<string, mixed> $details */
    public static function error(string $message, string $errorCode, int $status = 400, array $details = []): never
    {
        self::send([
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
            'details' => $details,
        ], $status);
    }

    /** @param array<string, mixed> $payload */
    private static function send(array $payload, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}
