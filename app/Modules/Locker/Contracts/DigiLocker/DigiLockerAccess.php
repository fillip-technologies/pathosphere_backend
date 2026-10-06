<?php

namespace App\Modules\Locker\Contracts\DigiLocker;

use Carbon\CarbonImmutable;

/** Permission to read one person's DigiLocker until it expires. */
final class DigiLockerAccess
{
    public function __construct(
        public readonly string $accessToken,
        public readonly CarbonImmutable $expiresAt,
        /** DigiLocker's own ID for the account. */
        public readonly string $digiLockerId,
        /** The name on the DigiLocker account, shown so the patient sees whose documents these are. */
        public readonly ?string $name,
    ) {}
}
