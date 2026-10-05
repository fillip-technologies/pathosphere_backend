<?php

namespace App\Modules\Auth\Enums;

enum OtpPurpose: string
{
    case Login = 'login';
    case PhoneVerify = 'phone_verify';
    case ReportAccess = 'report_access';
    case PasswordReset = 'password_reset';
}
