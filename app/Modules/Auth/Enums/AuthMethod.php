<?php

namespace App\Modules\Auth\Enums;

enum AuthMethod: string
{
    case Password = 'password';
    case Otp = 'otp';
    case Abha = 'abha';
}
