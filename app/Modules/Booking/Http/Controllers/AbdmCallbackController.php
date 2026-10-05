<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Modules\Booking\Contracts\AbdmClient;
use App\Modules\Booking\Enums\AbdmDirection;
use App\Modules\Booking\Enums\AbdmRequestStatus;
use App\Modules\Booking\Errors\AbdmError;
use App\Modules\Booking\Models\AbdmRequest;
use App\Modules\Booking\Services\AbhaService;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * POST /abdm/callbacks/{callbackType}: calls from the ABDM gateway, verified
 * by the gateway signature. M1 handles "Scan and Share" profiles; other
 * callbacks are logged for the M2 work in Phase 8.
 */
final class AbdmCallbackController
{
    public function __invoke(Request $request, string $callbackType, AbdmClient $abdm, AbhaService $abha, NetworkDirectory $network): JsonResponse
    {
        $headers = array_map(fn (array $values) => (string) ($values[0] ?? ''), $request->headers->all());

        if (! $abdm->verifyCallback($headers, $request->getContent())) {
            throw AbdmError::invalidCallback();
        }

        $requestId = (string) ($request->input('requestId') ?? Str::uuid());

        WithSystemScope::run(null, function () use ($request, $callbackType, $requestId, $abha, $network): void {
            if ($callbackType === 'profile-share') {
                $branchId = $network->branchIdByHfrId((string) $request->input('hipId'));

                if ($branchId !== null) {
                    $abha->receiveSharedProfile($branchId, $requestId, (array) $request->input('profile', []));

                    return;
                }
            }

            AbdmRequest::query()->firstOrCreate(['request_id' => $requestId], [
                'direction' => AbdmDirection::Callback,
                'api_name' => mb_substr($callbackType, 0, 80),
                'status' => AbdmRequestStatus::Pending,
            ]);
        });

        return new JsonResponse(['data' => ['accepted' => true]], 202);
    }
}
