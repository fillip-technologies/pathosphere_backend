<?php

namespace App\Modules\Lab\Services;

use Carbon\CarbonImmutable;

/** The doctor who signed one department of a report (ABDM: a FHIR Practitioner). */
final class ExchangeSigner
{
    public function __construct(
        public readonly string $signatoryId,
        public readonly string $departmentId,
        public readonly string $name,
        public readonly string $qualification,
        public readonly string $councilName,
        public readonly string $registrationNo,
        /** ABDM Health Professional Registry ID, when registered. */
        public readonly ?string $hprId,
        public readonly CarbonImmutable $signedAt,
    ) {}
}
