<?php

namespace App\Modules\Lab\Services;

/** One test of a released report, coded for exchange (ABDM: one FHIR DiagnosticReport). */
final class ExchangeTest
{
    /** @param  array<string, string>  $parameterLoincCodes  LOINC by parameter code, where known */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $loincCode,
        public readonly string $departmentId,
        public readonly string $departmentName,
        public readonly array $parameterLoincCodes,
    ) {}
}
