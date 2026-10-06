<?php

namespace App\Modules\Locker\Http\Controllers;

use App\Modules\Booking\Services\AbdmRequestLog;
use App\Modules\Locker\Contracts\AbdmHipGateway;
use App\Modules\Locker\Jobs\ProcessHipCallback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /abdm/callbacks/hip/{callbackType}: the ABDM gateway calling us as a
 * Health Information Provider (spec §5.7 M2, §8 "ABDM gateway signature").
 *
 * Verified, logged once per request ID (the gateway retries) and answered
 * 202 at once; the work and our answer to ABDM follow from a queued job.
 */
final class AbdmHipCallbackController
{
    public function __invoke(Request $request, string $callbackType, AbdmHipGateway $gateway, AbdmRequestLog $requestLog): JsonResponse
    {
        $headers = array_map(fn (array $values) => (string) ($values[0] ?? ''), $request->headers->all());
        $callback = $gateway->readCallback($callbackType, $headers, $request->getContent());

        if ($requestLog->callbackOnce($callback->apiName(), $callback->requestId(), $callback->transactionId())) {
            ProcessHipCallback::dispatch($callback);
        }

        return new JsonResponse(['data' => ['accepted' => true]], 202);
    }
}
