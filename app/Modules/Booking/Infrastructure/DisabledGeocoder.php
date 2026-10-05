<?php

namespace App\Modules\Booking\Infrastructure;

use App\Modules\Booking\Contracts\Geocoder;

/** No maps vendor chosen yet: home-collection addresses keep no coordinates. */
final class DisabledGeocoder implements Geocoder
{
    public function geocode(string $address, string $pincode): ?array
    {
        return null;
    }
}
