<?php

namespace App\Modules\Booking\Services;

use App\Modules\Shared\Enums\Gender;
use Carbon\CarbonImmutable;

/**
 * A patient whose ABHA is linked, as ABDM needs them to link records and
 * as a shared FHIR record names them (spec §5.7). Holds the ABHA number in
 * clear: pass it to ABDM, never log or store it.
 */
final class AbhaIdentity
{
    public function __construct(
        public readonly string $patientId,
        public readonly string $organizationId,
        public readonly string $uhid,
        public readonly string $name,
        public readonly Gender $gender,
        public readonly ?CarbonImmutable $dob,
        public readonly ?int $yearOfBirth,
        public readonly string $abhaNumber,
        public readonly ?string $abhaAddress,
    ) {}
}
