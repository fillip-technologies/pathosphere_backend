<?php

namespace App\Modules\Locker\Domain;

use App\Modules\Shared\Enums\Gender;
use Carbon\CarbonImmutable;

/** The patient a shared FHIR record is about. */
final class BundleSubject
{
    public function __construct(
        public readonly string $uhid,
        public readonly string $name,
        public readonly Gender $gender,
        public readonly ?CarbonImmutable $dob,
        public readonly ?string $abhaNumber,
        public readonly ?string $abhaAddress,
    ) {}
}
