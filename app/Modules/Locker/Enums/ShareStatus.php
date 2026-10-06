<?php

namespace App\Modules\Locker\Enums;

/** A share is usable only while active, unexpired and its consent granted. */
enum ShareStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
