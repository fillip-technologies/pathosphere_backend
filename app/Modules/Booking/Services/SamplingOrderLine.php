<?php

namespace App\Modules\Booking\Services;

/** One ordered test and the lab that will run it. */
final class SamplingOrderLine
{
    public function __construct(
        public readonly string $orderItemId,
        public readonly string $testId,
        public readonly string $processingBranchId,
    ) {}
}
