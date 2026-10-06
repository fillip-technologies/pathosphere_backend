<?php

namespace App\Modules\Locker\Enums;

/** Whether ABDM knows a report belongs to the patient's ABHA (spec §5.7 abdm_care_contexts). */
enum CareContextLinkStatus: string
{
    case Pending = 'pending';
    case Linked = 'linked';
    case Failed = 'failed';
}
