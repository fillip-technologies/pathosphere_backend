<?php

namespace App\Modules\Booking\Contracts;

/** Maps vendor boundary (Google Maps, Ola Maps, Mapbox — spec §3). */
interface Geocoder
{
    /** @return array{latitude: string, longitude: string}|null null when the address cannot be placed */
    public function geocode(string $address, string $pincode): ?array;
}
