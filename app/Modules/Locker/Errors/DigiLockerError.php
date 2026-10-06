<?php

namespace App\Modules\Locker\Errors;

use App\Modules\Shared\Errors\DomainError;

/** DigiLocker import failures with stable codes (spec §3). */
final class DigiLockerError extends DomainError
{
    public static function unavailable(): self
    {
        return new self('DIGILOCKER_UNAVAILABLE', 'DigiLocker is not responding. Try again in a few minutes.', 503);
    }

    public static function authorizationFailed(): self
    {
        return new self('DIGILOCKER_AUTHORIZATION_FAILED', 'DigiLocker did not accept this sign-in. Start again from DigiLocker.', 422, [['field' => 'code']]);
    }

    public static function sessionNotFound(): self
    {
        return new self('DIGILOCKER_SESSION_NOT_FOUND', 'This DigiLocker connection has ended. Connect to DigiLocker again.', 404);
    }

    public static function stateMismatch(): self
    {
        return new self('DIGILOCKER_STATE_MISMATCH', 'This DigiLocker sign-in was started somewhere else. Start again.', 422, [['field' => 'state']]);
    }

    public static function notAuthorized(): self
    {
        return new self('DIGILOCKER_NOT_AUTHORIZED', 'Sign in to DigiLocker and allow access first.', 409);
    }

    public static function alreadyAuthorized(): self
    {
        return new self('DIGILOCKER_ALREADY_AUTHORIZED', 'This DigiLocker connection is already signed in.', 409);
    }

    public static function documentNotFound(): self
    {
        return new self('DIGILOCKER_DOCUMENT_NOT_FOUND', 'This document is not in the DigiLocker you connected.', 404, [['field' => 'uri']]);
    }

    public static function fileNotSupported(): self
    {
        return new self('DIGILOCKER_FILE_NOT_SUPPORTED', 'Only PDF and image documents can be added to the health locker.', 422, [['field' => 'uri']]);
    }

    public static function fileTooLarge(int $maxKb): self
    {
        return new self('DIGILOCKER_FILE_TOO_LARGE', "This document is larger than {$maxKb} KB.", 422, [['field' => 'uri']]);
    }

    public static function importedElsewhere(): self
    {
        return new self('DIGILOCKER_DOCUMENT_IN_ANOTHER_LOCKER', 'This document is already in another health locker.', 409, [['field' => 'uri']]);
    }
}
