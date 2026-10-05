<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Modules\Shared\Errors\ErrorCode;
use App\Modules\Shared\Http\Responses\ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Liveness check for uptime monitors and the deploy smoke test (spec §2). */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            report($exception);

            return ErrorResponse::make(
                ErrorCode::SERVICE_UNAVAILABLE,
                'The database is not reachable.',
                503,
                [['check' => 'database', 'status' => 'failing']],
            );
        }

        return new JsonResponse([
            'data' => [
                'status' => 'ok',
                'checks' => ['database' => 'ok'],
                'time' => now()->utc()->toIso8601ZuluString(),
            ],
        ]);
    }
}
