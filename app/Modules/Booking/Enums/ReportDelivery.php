<?php

namespace App\Modules\Booking\Enums;

/** How a referring doctor receives reports (spec §7.3). */
enum ReportDelivery: string
{
    case None = 'none';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Email = 'email';
}
