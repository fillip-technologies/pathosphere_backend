<?php

namespace App\Modules\Shared\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The one error body used by every endpoint (BUILD_PLAN decision D1):
 * { "error": { "code", "message", "status", "details": [] } }
 */
final class ErrorResponse
{
    /**
     * @param  list<array<string, mixed>>  $details
     * @param  array<string, string>  $headers
     */
    public static function make(string $code, string $message, int $status, array $details = [], array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'status' => $status,
                'details' => $details,
            ],
        ], $status, $headers);
    }
}
