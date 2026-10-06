<?php

namespace App\Modules\Network\Enums;

/** Franchise agreement lifecycle (spec §5.1). */
enum AgreementStatus: string
{
    case Draft = 'draft';
    case SentForSign = 'sent_for_sign';
    case Active = 'active';
    case Expired = 'expired';
    case Terminated = 'terminated';
}
