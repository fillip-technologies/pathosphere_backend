<?php

namespace App\Modules\Shared\Enums;

/** Patient gender; drives reference ranges (spec §7.3, §7.4). */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
    case Other = 'other';
    case Unknown = 'unknown';
}
