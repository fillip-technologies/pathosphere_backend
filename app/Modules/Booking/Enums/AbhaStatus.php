<?php

namespace App\Modules\Booking\Enums;

/** ABHA link state on a patient (spec §5.7). */
enum AbhaStatus: string
{
    case NotLinked = 'not_linked';
    case Linked = 'linked';
    case Unlinked = 'unlinked';
}
