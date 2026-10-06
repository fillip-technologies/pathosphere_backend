<?php

namespace App\Modules\Booking\Domain;

use InvalidArgumentException;

/** How phlebotomists move through a city, from configuration (spec §5.3). */
final class TravelAssumptions
{
    public function __construct(
        public readonly int $speedKmh,
        public readonly int $visitMinutes,
        public readonly int $roadFactorPercent,
    ) {
        if ($speedKmh < 1 || $visitMinutes < 0 || $roadFactorPercent < 100) {
            throw new InvalidArgumentException('Travel speed must be positive, visit time not negative and the road factor at least 100%.');
        }
    }

    public function metres(?GeoPoint $from, ?GeoPoint $to): int
    {
        return RoadDistance::metres($from, $to, $this->roadFactorPercent);
    }

    public function travelSeconds(int $metres): int
    {
        return intdiv($metres * 3600 + $this->speedKmh * 1000 - 1, $this->speedKmh * 1000);
    }

    public function visitSeconds(): int
    {
        return $this->visitMinutes * 60;
    }
}
