<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\AbdmDirection;
use App\Modules\Booking\Enums\AbdmRequestStatus;
use App\Modules\Booking\Models\AbdmRequest;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * abdm_requests for callers outside Booking (spec §5.7: a log of every call
 * to and callback from ABDM). Payloads must already be masked: no OTPs,
 * Aadhaar numbers, ABHA numbers, link tokens or health data.
 *
 * ABDM answers asynchronously, by calling us back with the ID of our
 * request; the row written before the call is how the answer finds its way.
 */
final class AbdmRequestLog
{
    public function __construct(private readonly CurrentScope $currentScope) {}

    /** @param  array<string, mixed>|null  $payloadMasked */
    public function outbound(
        string $apiName,
        string $requestId,
        AbdmRequestStatus $status,
        ?string $patientId = null,
        ?string $branchId = null,
        ?string $txnId = null,
        ?string $errorCode = null,
        ?array $payloadMasked = null,
    ): void {
        $this->asSystem(fn () => AbdmRequest::query()->create([
            'patient_id' => $patientId,
            'branch_id' => $branchId,
            'direction' => AbdmDirection::Outbound,
            'api_name' => mb_substr($apiName, 0, 80),
            'request_id' => $requestId,
            'txn_id' => $txnId,
            'status' => $status,
            'error_code' => $errorCode,
            'payload_masked' => $payloadMasked,
        ]));
    }

    /**
     * Records a callback once. False when this request ID was seen before:
     * the gateway retries, and a retry must not be acted on twice.
     *
     * @param  array<string, mixed>|null  $payloadMasked
     */
    public function callbackOnce(string $apiName, string $requestId, ?string $txnId = null, ?string $branchId = null, ?array $payloadMasked = null): bool
    {
        try {
            $this->asSystem(fn () => AbdmRequest::query()->create([
                'branch_id' => $branchId,
                'direction' => AbdmDirection::Callback,
                'api_name' => mb_substr($apiName, 0, 80),
                'request_id' => $requestId,
                'txn_id' => $txnId,
                'status' => AbdmRequestStatus::Pending,
                'payload_masked' => $payloadMasked,
            ]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /** Who and where an earlier outbound request was about, to act on ABDM's answer to it. */
    public function outboundRequest(string $requestId): ?LoggedAbdmRequest
    {
        return $this->asSystem(function () use ($requestId): ?LoggedAbdmRequest {
            $row = AbdmRequest::query()
                ->where('request_id', $requestId)
                ->where('direction', AbdmDirection::Outbound)
                ->first();

            return $row === null ? null : new LoggedAbdmRequest($row->request_id, $row->api_name, $row->status, $row->patient_id, $row->branch_id);
        });
    }

    /** The outcome of a request or callback, once known. */
    public function settle(string $requestId, AbdmRequestStatus $status, ?string $errorCode = null, ?string $patientId = null): void
    {
        $this->asSystem(function () use ($requestId, $status, $errorCode, $patientId): void {
            $row = AbdmRequest::query()->where('request_id', $requestId)->first();

            if ($row === null) {
                return;
            }

            $row->status = $status;
            $row->error_code = $errorCode;
            $row->patient_id ??= $patientId;
            $row->save();
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asSystem(callable $callback): mixed
    {
        return $this->currentScope->runAs(ScopeContext::system(), $callback);
    }
}
