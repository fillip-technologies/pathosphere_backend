<?php

namespace App\Modules\Catalogue\Services;

/** The container a test needs and how long that sample survives transit. */
final class SampleRequirement
{
    public function __construct(
        public readonly string $testId,
        public readonly string $code,
        public readonly string $name,
        /** Short name for tube labels; falls back to the code. */
        public readonly string $labelName,
        public readonly string $sampleType,
        public readonly string $containerType,
        public readonly ?int $stabilityHours,
    ) {}
}
