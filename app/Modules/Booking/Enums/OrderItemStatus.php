<?php

namespace App\Modules\Booking\Enums;

enum OrderItemStatus: string
{
    case Ordered = 'ordered';
    case Collected = 'collected';
    case Processing = 'processing';
    case Reported = 'reported';
    case Cancelled = 'cancelled';
    case Recollect = 'recollect';
}
