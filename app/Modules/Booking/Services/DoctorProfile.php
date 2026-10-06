<?php

namespace App\Modules\Booking\Services;

/** A referring doctor as a patient sharing records, or the doctor themselves, sees them. */
final class DoctorProfile
{
    public function __construct(
        public readonly string $id,
        public readonly string $organizationId,
        public readonly string $name,
        public readonly ?string $specialization,
        public readonly ?string $clinicName,
    ) {}
}
