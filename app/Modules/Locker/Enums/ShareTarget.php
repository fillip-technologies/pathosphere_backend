<?php

namespace App\Modules\Locker\Enums;

/** Who a record is shared with (spec §7.11 record_shares.shared_with_type). */
enum ShareTarget: string
{
    case Doctor = 'doctor';
    case Email = 'email';
    case Link = 'link';
}
