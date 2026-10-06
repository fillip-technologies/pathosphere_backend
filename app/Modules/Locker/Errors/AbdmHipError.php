<?php

namespace App\Modules\Locker\Errors;

use App\Modules\Shared\Errors\DomainError;

/** Failures at the ABDM gateway boundary (spec §5.7 M2). */
final class AbdmHipError extends DomainError
{
    public static function invalidSignature(): self
    {
        return new self('INVALID_SIGNATURE', 'The ABDM callback could not be verified.', 401);
    }

    /** @param  list<array<string, mixed>>  $details */
    public static function malformed(array $details): self
    {
        return new self('ABDM_CALLBACK_MALFORMED', 'The ABDM callback is missing required fields.', 400, $details);
    }

    public static function unavailable(): self
    {
        return new self('ABDM_UNAVAILABLE', 'ABDM is not responding.', 503);
    }
}
