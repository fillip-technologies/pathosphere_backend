<?php

namespace App\Modules\Booking\Services;

use App\Modules\Shared\Enums\Gender;
use Carbon\CarbonImmutable;

/**
 * The order and patient details a report prints and a lab needs to choose
 * reference ranges. Identity only: no phone, address, ABHA or money.
 */
final class ReportOrderFacts
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $organizationId,
        public readonly string $orderNo,
        public readonly CarbonImmutable $orderDate,
        public readonly string $branchId,
        public readonly ?string $franchiseId,
        public readonly ?string $b2bClientId,
        public readonly string $patientId,
        public readonly string $patientName,
        public readonly string $uhid,
        public readonly ?int $ageYears,
        /** Age for reference ranges; from the date of birth when known. */
        public readonly ?int $ageDays,
        public readonly Gender $gender,
        public readonly ?string $doctorName,
    ) {}

    /** e.g. "A.K." for the public verification page. */
    public function patientInitials(): string
    {
        $words = preg_split('/\s+/', trim($this->patientName)) ?: [];

        return implode('', array_map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)).'.', array_filter($words)));
    }
}
