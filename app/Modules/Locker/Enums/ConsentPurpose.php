<?php

namespace App\Modules\Locker\Enums;

/** Why records are shared (spec §7.11 consents.purpose). */
enum ConsentPurpose: string
{
    case Treatment = 'treatment';
    case SecondOpinion = 'second_opinion';
    case Insurance = 'insurance';
    case Personal = 'personal';
    case Other = 'other';
}
