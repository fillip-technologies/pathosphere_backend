<?php

namespace App\Modules\Booking\Services;

use App\Modules\Shared\Enums\Gender;

/**
 * A patient who may be the person ABDM asks about when they look for their
 * records from their ABHA app: same verified mobile, or same ABHA number
 * linked at our desk. Whether they really are is the caller's decision.
 */
final class AbhaDiscoveryCandidate
{
    public function __construct(
        public readonly string $patientId,
        public readonly string $organizationId,
        public readonly string $uhid,
        public readonly string $name,
        public readonly Gender $gender,
        public readonly ?int $yearOfBirth,
        /** The ABHA number ABDM verified is the one linked to this patient at our desk. */
        public readonly bool $abhaNumberMatches,
        public readonly bool $phoneMatches,
    ) {}
}
