<?php

namespace App\Modules\Lab\Services;

use Carbon\CarbonImmutable;

/** One value read by an analyser, as the interface agent sends it. */
final class AnalyserResult
{
    public function __construct(
        public readonly string $barcode,
        public readonly string $testCode,
        public readonly string $parameterCode,
        public readonly string $value,
        public readonly ?string $instrument,
        public readonly CarbonImmutable $measuredAt,
        /** The run the agent means; null for the test's current run. */
        public readonly ?int $runNo,
    ) {}
}
