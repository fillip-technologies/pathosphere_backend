<?php

namespace App\Modules\Booking\Contracts;

/** An ABHA profile as returned by ABDM, with nothing Aadhaar-related in it. */
final class AbhaProfile
{
    public function __construct(
        public readonly string $abhaNumber,
        public readonly ?string $abhaAddress,
        public readonly string $name,
        /** M, F or O as ABDM sends it. */
        public readonly string $gender,
        public readonly ?int $yearOfBirth,
        public readonly ?string $mobile,
        public readonly bool $kycVerified,
    ) {}

    /**
     * What is kept on the patient (spec §5.7: name, gender, year of birth,
     * mobile; no Aadhaar).
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'gender' => $this->gender,
            'year_of_birth' => $this->yearOfBirth,
            'mobile' => $this->mobile,
        ];
    }
}
