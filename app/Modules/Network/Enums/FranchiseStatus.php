<?php

namespace App\Modules\Network\Enums;

enum FranchiseStatus: string
{
    case Applied = 'applied';
    case KycPending = 'kyc_pending';
    case Approved = 'approved';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
}
