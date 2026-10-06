<?php

namespace App\Modules\Locker\Contracts;

use App\Modules\Locker\Contracts\DigiLocker\DigiLockerAccess;
use App\Modules\Locker\Contracts\DigiLocker\DigiLockerDocument;
use App\Modules\Locker\Contracts\DigiLocker\DigiLockerFile;
use App\Modules\Locker\Errors\DigiLockerError;

/**
 * DigiLocker boundary (spec §3: DigiLocker partner API via API Setu,
 * optional pull of patient documents into the health locker; separate
 * onboarding). The patient signs in at DigiLocker and allows us to read
 * their issued documents (OAuth 2 authorization code with PKCE); we never
 * see their DigiLocker password or keep their token beyond the session.
 *
 * Failures to reach DigiLocker throw DigiLockerError::unavailable().
 */
interface DigiLocker
{
    /** The DigiLocker page the patient is sent to; it comes back to our redirect URI with a code and the state. */
    public function authorizationUrl(string $state, string $codeChallenge): string;

    /** @throws DigiLockerError when the code is wrong, used or expired */
    public function exchangeCode(string $code, string $codeVerifier): DigiLockerAccess;

    /**
     * Documents issued into the patient's DigiLocker by government and
     * other issuers (vaccination certificates, health cards, discharge
     * summaries from linked hospitals…).
     *
     * @return list<DigiLockerDocument>
     */
    public function issuedDocuments(DigiLockerAccess $access): array;

    public function file(DigiLockerAccess $access, string $uri): DigiLockerFile;
}
