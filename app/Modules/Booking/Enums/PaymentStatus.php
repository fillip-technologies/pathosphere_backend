<?php

namespace App\Modules\Booking\Enums;

enum PaymentStatus: string
{
    case Success = 'success';
    case Pending = 'pending';
    case Failed = 'failed';
}
