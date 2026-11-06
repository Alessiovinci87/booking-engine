<?php

namespace App\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Formato degli errori dell'API (07): { "error": { code, message, details, request_id } }.
 * Il messaggio è localizzato da lang/<locale>/api.php; il client si basa sempre su code.
 */
final class ApiError
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function response(int $status, string $code, array $details = [], array $headers = []): JsonResponse
    {
        $key = 'api.errors.'.$code;
        $message = __($key);

        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => is_string($message) && $message !== $key ? $message : $code,
                'details' => (object) $details,
                'request_id' => (string) Str::uuid7(),
            ],
        ], $status, $headers);
    }
}
