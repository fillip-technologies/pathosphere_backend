<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\AbdmRequestStatus;

/** An earlier call to ABDM, as logged in abdm_requests. */
final class LoggedAbdmRequest
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $apiName,
        public readonly AbdmRequestStatus $status,
        public readonly ?string $patientId,
        public readonly ?string $branchId,
    ) {}
}
