<?php

namespace App\Modules\Samples\Domain;

/** An ordered test that still needs a container, with what that container must be. */
final class SamplingLine
{
    public function __construct(
        public readonly string $orderItemId,
        public readonly string $testId,
        public readonly string $processingBranchId,
        public readonly string $sampleType,
        public readonly string $containerType,
    ) {}
}
