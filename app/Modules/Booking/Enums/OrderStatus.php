<?php

namespace App\Modules\Booking\Enums;

/** Order lifecycle (spec §5.2). */
enum OrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case PartiallyReported = 'partially_reported';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
