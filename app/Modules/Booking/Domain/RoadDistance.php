<?php

namespace App\Modules\Booking\Domain;

/**
 * Estimated road distance between two places: the great-circle distance
 * (haversine) stretched by a road factor, because streets are not straight.
 * Good enough to compare visits across a city without a routing vendor; a
 * maps API can replace it behind the same answer (whole metres).
 */
final class RoadDistance
{
    private const EARTH_RADIUS_METRES = 6_371_000;

    public static function metres(?GeoPoint $from, ?GeoPoint $to, int $roadFactorPercent): int
    {
        // An unplaced visit adds no known distance; time windows still apply.
        if ($from === null || $to === null) {
            return 0;
        }

        $fromLatitude = deg2rad($from->latitudeMicro / 1_000_000);
        $toLatitude = deg2rad($to->latitudeMicro / 1_000_000);
        $latitudeDelta = $toLatitude - $fromLatitude;
        $longitudeDelta = deg2rad(($to->longitudeMicro - $from->longitudeMicro) / 1_000_000);

        $haversine = sin($latitudeDelta / 2) ** 2 + cos($fromLatitude) * cos($toLatitude) * sin($longitudeDelta / 2) ** 2;
        $straightLine = 2 * self::EARTH_RADIUS_METRES * asin(min(1, sqrt($haversine)));

        return (int) round($straightLine * $roadFactorPercent / 100);
    }
}
