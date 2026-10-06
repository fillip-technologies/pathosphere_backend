<?php

namespace App\Modules\Samples\Services;

use App\Modules\Samples\Enums\SampleStatus;
use Carbon\CarbonImmutable;

/** A sample as the lab working on it needs it. */
final class LabSampleFacts
{
    /** @param  list<string>  $orderItemIds */
    public function __construct(
        public readonly string $id,
        public readonly string $organizationId,
        public readonly string $orderId,
        public readonly string $barcode,
        public readonly string $sampleType,
        public readonly string $collectedBranchId,
        public readonly string $processingBranchId,
        public readonly string $currentBranchId,
        public readonly SampleStatus $status,
        public readonly ?CarbonImmutable $collectedAt,
        public readonly ?CarbonImmutable $receivedAt,
        public readonly array $orderItemIds,
    ) {}

    /** At the lab that runs its tests, accepted and not yet finished. */
    public function isAtItsLab(): bool
    {
        return $this->currentBranchId === $this->processingBranchId
            && in_array($this->status, [SampleStatus::Received, SampleStatus::InProcess], true);
    }
}
