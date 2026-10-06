<?php

namespace App\Modules\Network\Enums;

/** The two kinds of partner HQ keeps an account with (spec §5.6). */
enum PartnerType: string
{
    case Franchise = 'franchise';
    case B2bClient = 'b2b_client';
}
