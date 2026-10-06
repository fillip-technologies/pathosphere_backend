<?php

namespace App\Modules\Locker\Contracts\Abdm;

use Carbon\CarbonImmutable;

/** Our answer to a link request: a code was sent to the patient's phone. */
final class LinkChallenge
{
    public function __construct(
        public readonly string $linkReference,
        /** The phone with all but its last digits hidden. */
        public readonly string $communicationHint,
        public readonly CarbonImmutable $expiresAt,
    ) {}
}
