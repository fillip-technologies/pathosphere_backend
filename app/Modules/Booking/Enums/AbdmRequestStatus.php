<?php

namespace App\Modules\Booking\Enums;

enum AbdmRequestStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Timeout = 'timeout';
}
