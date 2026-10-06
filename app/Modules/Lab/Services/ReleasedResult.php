<?php

namespace App\Modules\Lab\Services;

/** One printed result line of a released report. */
final class ReleasedResult
{
    public function __construct(
        public readonly string $testCode,
        public readonly string $testName,
        public readonly string $parameterCode,
        public readonly string $parameterName,
        public readonly ?string $value,
        /** Decimal string, never a float. */
        public readonly ?string $valueNumeric,
        public readonly ?string $unit,
        public readonly ?string $referenceRange,
        public readonly ?string $flag,
    ) {}
}
