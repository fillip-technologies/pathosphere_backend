<?php

namespace App\Modules\Booking\Enums;

/** Home visit lifecycle (spec §5.3). */
enum HomeCollectionStatus: string
{
    case Scheduled = 'scheduled';
    case Assigned = 'assigned';
    case EnRoute = 'en_route';
    case Collected = 'collected';
    case HandedOver = 'handed_over';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
