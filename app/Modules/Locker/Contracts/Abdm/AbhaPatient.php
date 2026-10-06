<?php

namespace App\Modules\Locker\Contracts\Abdm;

/** The patient as ABDM knows them, for linking records to their ABHA. */
final class AbhaPatient
{
    public function __construct(
        public readonly string $abhaNumber,
        public readonly ?string $abhaAddress,
        public readonly string $name,
        /** M, F or O, as ABDM writes it. */
        public readonly string $gender,
        public readonly ?int $yearOfBirth,
    ) {}
}
