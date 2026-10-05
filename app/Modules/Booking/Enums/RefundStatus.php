<?php

namespace App\Modules\Booking\Enums;

enum RefundStatus: string
{
    case Requested = 'requested';
    case Processed = 'processed';
    case Failed = 'failed';
}
