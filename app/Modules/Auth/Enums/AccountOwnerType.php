<?php

namespace App\Modules\Auth\Enums;

enum AccountOwnerType: string
{
    case User = 'user';
    case Patient = 'patient';
    case Doctor = 'doctor';
}
