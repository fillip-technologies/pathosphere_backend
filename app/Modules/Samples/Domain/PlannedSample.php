<?php

namespace App\Modules\Samples\Domain;

/** One container to draw, and the order items (tests) it serves. */
final class PlannedSample
{
    /**
     * @param  list<string>  $orderItemIds
     * @param  list<string>  $testIds
     */
    public function __construct(
        public readonly string $processingBranchId,
        public readonly string $sampleType,
        public readonly string $containerType,
        public readonly array $orderItemIds,
        public readonly array $testIds,
    ) {}
}
