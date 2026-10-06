<?php

namespace App\Modules\Auth\Errors;

use App\Modules\Shared\Errors\DomainError;

/**
 * Authentication and RBAC failures with stable codes. Login failures stay
 * deliberately vague so they do not reveal which accounts exist.
 */
final class AuthError
{
    public static function invalidCredentials(): DomainError
    {
        return new DomainError('INVALID_CREDENTIALS', 'The login details are incorrect.', 401);
    }

    public static function accountLocked(): DomainError
    {
        return new DomainError('ACCOUNT_LOCKED', 'Too many failed attempts. The account is locked for a few minutes.', 401);
    }

    public static function accountDisabled(): DomainError
    {
        return new DomainError('ACCOUNT_DISABLED', 'This account is disabled. Contact your administrator.', 401);
    }

    public static function invalidRefreshToken(): DomainError
    {
        return new DomainError('INVALID_REFRESH_TOKEN', 'The session has expired. Sign in again.', 401);
    }

    public static function invalidMfaChallenge(): DomainError
    {
        return new DomainError('MFA_CHALLENGE_INVALID', 'The sign-in attempt has expired. Sign in again.', 401);
    }

    public static function invalidMfaCode(): DomainError
    {
        return new DomainError('MFA_CODE_INVALID', 'The authenticator code is incorrect.', 401);
    }

    public static function mfaNotSetUp(): DomainError
    {
        return new DomainError('MFA_NOT_SET_UP', 'Set up your authenticator app before signing reports.', 403);
    }

    public static function mfaEnrollmentNotStarted(): DomainError
    {
        return new DomainError('MFA_ENROLLMENT_NOT_STARTED', 'Start MFA enrolment before confirming a code.', 422);
    }

    public static function notStaff(): DomainError
    {
        return new DomainError('FORBIDDEN', 'This endpoint is for staff accounts.', 403);
    }

    public static function wrongAccountType(string $expected): DomainError
    {
        return new DomainError('FORBIDDEN', "This endpoint is for {$expected} accounts.", 403);
    }

    public static function otpInvalid(): DomainError
    {
        return new DomainError('OTP_INVALID', 'The code is incorrect.', 401);
    }

    public static function otpExpired(): DomainError
    {
        return new DomainError('OTP_EXPIRED', 'The code has expired. Request a new one.', 401);
    }

    public static function otpAttemptsExceeded(): DomainError
    {
        return new DomainError('OTP_ATTEMPTS_EXCEEDED', 'Too many wrong codes. Request a new one.', 401);
    }

    public static function otpResendTooSoon(int $retryAfterSeconds): DomainError
    {
        return new DomainError('OTP_RESEND_TOO_SOON', 'A code was sent moments ago. Wait before asking for another.', 429, [
            ['field' => 'phone', 'retry_after_seconds' => $retryAfterSeconds],
        ]);
    }

    public static function otpDailyLimitReached(): DomainError
    {
        return new DomainError('OTP_DAILY_LIMIT', 'Too many codes were sent to this phone today. Try again tomorrow.', 429);
    }

    public static function roleNotAssignable(string $reason): DomainError
    {
        return new DomainError('ROLE_NOT_ASSIGNABLE', $reason, 403);
    }

    public static function systemRoleReadOnly(): DomainError
    {
        return new DomainError('SYSTEM_ROLE_READ_ONLY', 'System roles cannot be changed or deleted. Create a new role instead.', 422);
    }

    public static function roleInUse(): DomainError
    {
        return new DomainError('ROLE_IN_USE', 'Staff still hold this role. Move them to another role first.', 409);
    }

    /** @param  list<string>  $permissionNames */
    public static function permissionsAboveScope(array $permissionNames, string $scopeLevel): DomainError
    {
        return new DomainError(
            'PERMISSION_ABOVE_SCOPE',
            "A {$scopeLevel}-level role cannot hold these permissions.",
            422,
            array_map(fn (string $name): array => ['field' => 'permissions', 'permission' => $name], $permissionNames),
        );
    }

    public static function cannotChangeOwnAccess(): DomainError
    {
        return new DomainError('CANNOT_CHANGE_OWN_ACCESS', 'You cannot disable, delete or change the role of your own account.', 422);
    }
}
