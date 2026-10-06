<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\AbhaStatus;
use App\Modules\Shared\Enums\Gender;
use Carbon\CarbonImmutable;

/** A patient as they see themselves in the health locker: identity, no address or ABHA number. */
final class PatientProfile
{
    public function __construct(
        public readonly string $id,
        public readonly string $organizationId,
        public readonly string $uhid,
        public readonly string $name,
        public readonly Gender $gender,
        public readonly ?CarbonImmutable $dob,
        public readonly ?int $ageYears,
        public readonly string $maskedPhone,
        public readonly AbhaStatus $abhaStatus,
        public readonly ?string $guardianPatientId,
    ) {}
}
