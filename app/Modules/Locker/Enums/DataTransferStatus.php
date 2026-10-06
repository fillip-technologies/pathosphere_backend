<?php

namespace App\Modules\Locker\Enums;

/** One ABDM health-information request, from receipt to delivery. */
enum DataTransferStatus: string
{
    /** Refused when it arrived (no consent in force, dates outside it, unsupported keys). */
    case Rejected = 'rejected';
    case Acknowledged = 'acknowledged';
    case Transferred = 'transferred';
    case Failed = 'failed';
}
