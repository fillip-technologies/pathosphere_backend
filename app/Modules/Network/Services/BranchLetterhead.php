<?php

namespace App\Modules\Network\Services;

use Carbon\CarbonImmutable;

/** A site as printed on a report: name, address and accreditation. */
final class BranchLetterhead
{
    public function __construct(
        public readonly string $branchId,
        public readonly string $branchCode,
        public readonly string $name,
        public readonly string $address,
        public readonly string $phone,
        public readonly ?string $nablCertificateNo,
        public readonly ?CarbonImmutable $nablValidTill,
        public readonly ?string $clinicalEstablishmentRegNo,
        /** ABDM Health Facility Registry ID: the lab's identity as a Health Information Provider. */
        public readonly ?string $hfrId = null,
    ) {}
}
