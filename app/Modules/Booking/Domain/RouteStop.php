<?php

namespace App\Modules\Booking\Domain;

/**
 * A home visit as the route planner sees it: where, and the slot the
 * patient booked (Unix seconds). The phlebotomist must arrive within the slot.
 */
final class RouteStop
{
    public function __construct(
        public readonly string $visitId,
        public readonly ?GeoPoint $point,
        public readonly int $slotStart,
        public readonly int $slotEnd,
    ) {}
}
