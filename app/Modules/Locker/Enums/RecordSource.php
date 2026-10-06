<?php

namespace App\Modules\Locker\Enums;

/** Where a health-locker record came from (spec §7.11). */
enum RecordSource: string
{
    case OwnLab = 'own_lab';
    case Abdm = 'abdm';
    case Digilocker = 'digilocker';
    case Upload = 'upload';
}
