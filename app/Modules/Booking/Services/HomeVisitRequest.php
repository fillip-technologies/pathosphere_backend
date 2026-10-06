<?php

namespace App\Modules\Booking\Services;

use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/** Where and when to collect samples at home (spec §5.3). */
final class HomeVisitRequest
{
    public function __construct(
        public readonly string $address,
        public readonly string $pincode,
        public readonly CarbonImmutable $slotStart,
        public readonly CarbonImmutable $slotEnd,
        public readonly Money $collectionCharge,
        /** Given together or not at all; null leaves placing the address to the geocoder. */
        public readonly ?string $latitude = null,
        public readonly ?string $longitude = null,
    ) {}
}
