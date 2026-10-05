<?php

namespace App\Modules\Auth\Enums;

enum UserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Disabled = 'disabled';
}
