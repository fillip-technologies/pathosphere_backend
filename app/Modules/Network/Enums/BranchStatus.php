<?php

namespace App\Modules\Network\Enums;

enum BranchStatus: string
{
    case Setup = 'setup';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
