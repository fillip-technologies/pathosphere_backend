<?php

namespace App\Modules\Locker\Infrastructure;

use App\Modules\Locker\Contracts\DigiLocker;
use App\Modules\Locker\Contracts\DigiLocker\DigiLockerAccess;
use App\Modules\Locker\Contracts\DigiLocker\DigiLockerFile;
use App\Modules\Locker\Errors\DigiLockerError;

/** No DigiLocker partner account yet: every call says DigiLocker is unavailable. */
final class DisabledDigiLocker implements DigiLocker
{
    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        throw DigiLockerError::unavailable();
    }

    public function exchangeCode(string $code, string $codeVerifier): DigiLockerAccess
    {
        throw DigiLockerError::unavailable();
    }

    public function issuedDocuments(DigiLockerAccess $access): array
    {
        throw DigiLockerError::unavailable();
    }

    public function file(DigiLockerAccess $access, string $uri): DigiLockerFile
    {
        throw DigiLockerError::unavailable();
    }
}
