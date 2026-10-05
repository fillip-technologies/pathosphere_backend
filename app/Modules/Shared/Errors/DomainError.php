<?php

namespace App\Modules\Shared\Errors;

use RuntimeException;

/**
 * A failure the client can act on: a business rule was broken, a state change
 * is not allowed, or a request conflicts with current data.
 *
 * The message is shown to users and written to logs, so it must never contain
 * patient names, phone numbers, ABHA numbers or secrets. Put identifiers the
 * client needs in `details` instead.
 */
class DomainError extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
