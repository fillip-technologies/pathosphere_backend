<?php

namespace App\Modules\Booking\Enums;

enum BillToType: string
{
    case Patient = 'patient';
    case B2bClient = 'b2b_client';
}
