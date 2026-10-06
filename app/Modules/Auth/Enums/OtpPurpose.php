<?php

namespace App\Modules\Auth\Enums;

enum OtpPurpose: string
{
    case Login = 'login';
    case PhoneVerify = 'phone_verify';
    case ReportAccess = 'report_access';
    case PasswordReset = 'password_reset';
    /** A patient proving, by a code to their phone, that ABDM may link their reports (Phase 8). */
    case CareContextLink = 'care_context_link';
}
