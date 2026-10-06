<?php

namespace App\Modules\Booking\Domain;

use InvalidArgumentException;

/**
 * A place on the map in millionths of a degree, as stored in the
 * `numeric(9,6)` latitude and longitude columns (spec §7.5).
 */
final class GeoPoint
{
    private function __construct(
        public readonly int $latitudeMicro,
        public readonly int $longitudeMicro,
    ) {}

    /** Null when either coordinate is missing: the place is unknown. */
    public static function fromColumns(?string $latitude, ?string $longitude): ?self
    {
        if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
            return null;
        }

        return new self(self::micro($latitude, 90), self::micro($longitude, 180));
    }

    private static function micro(string $degrees, int $limit): int
    {
        if (preg_match('/^(-?)(\d{1,3})(?:\.(\d{1,6}))?$/', trim($degrees), $parts) !== 1) {
            throw new InvalidArgumentException("Not a coordinate: {$degrees}");
        }

        $micro = (int) $parts[2] * 1_000_000 + (int) str_pad($parts[3] ?? '', 6, '0');

        if ($micro > $limit * 1_000_000) {
            throw new InvalidArgumentException("Coordinate out of range: {$degrees}");
        }

        return $parts[1] === '-' ? -$micro : $micro;
    }
}
