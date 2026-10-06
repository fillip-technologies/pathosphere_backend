<?php

namespace App\Modules\Lab\Enums;

/**
 * One test waiting at the lab that runs it: results pending, all entered,
 * all verified, or withdrawn because its sample was re-routed or rejected.
 */
enum WorklistStatus: string
{
    case Pending = 'pending';
    case Entered = 'entered';
    case Verified = 'verified';
    case Withdrawn = 'withdrawn';
}
