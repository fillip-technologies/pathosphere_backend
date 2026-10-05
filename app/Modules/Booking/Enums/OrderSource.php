<?php

namespace App\Modules\Booking\Enums;

/** How the booking reached us (spec §7.5). */
enum OrderSource: string
{
    case WalkIn = 'walk_in';
    case HomeCollection = 'home_collection';
    case B2b = 'b2b';
    case CorporateCamp = 'corporate_camp';
    case Online = 'online';
}
