<?php

namespace App\Modules\Network\Enums;

enum B2bClientStatus: string
{
    case Active = 'active';
    case OnHold = 'on_hold';
    case Closed = 'closed';
}
