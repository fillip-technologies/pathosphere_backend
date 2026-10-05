<?php

namespace App\Modules\Booking\Services;

/**
 * Order and patient details printed on tube labels and shown to the labs that
 * handle a sample. No phone, address or ABHA: labs testing another branch's
 * sample need to identify the patient, not contact them.
 */
final class SampleOrderFacts
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $orderNo,
        public readonly bool $isCancelled,
        public readonly string $patientId,
        public readonly string $patientName,
        public readonly string $uhid,
        public readonly ?int $ageYears,
        public readonly string $gender,
    ) {}

    /** e.g. "36Y F"; "?" when the age was never recorded. */
    public function ageAndGender(): string
    {
        $age = $this->ageYears === null ? '?' : "{$this->ageYears}Y";

        return $age.' '.strtoupper(substr($this->gender, 0, 1));
    }
}
