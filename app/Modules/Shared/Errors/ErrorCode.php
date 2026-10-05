<?php

namespace App\Modules\Shared\Errors;

/**
 * Stable, machine-readable error codes shared by every module. Clients branch
 * on these, so a code is never renamed once released (AGENT_RESTAPI rule 5).
 * Module-specific codes (PRICE_MISSING, WALLET_INSUFFICIENT, …) live with
 * their module's errors.
 */
final class ErrorCode
{
    public const MALFORMED_REQUEST = 'MALFORMED_REQUEST';

    public const VALIDATION_ERROR = 'VALIDATION_ERROR';

    public const UNAUTHENTICATED = 'UNAUTHENTICATED';

    public const FORBIDDEN = 'FORBIDDEN';

    public const NOT_FOUND = 'NOT_FOUND';

    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';

    public const CONFLICT = 'CONFLICT';

    public const PRECONDITION_FAILED = 'PRECONDITION_FAILED';

    public const PRECONDITION_REQUIRED = 'PRECONDITION_REQUIRED';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const INVALID_STATUS_TRANSITION = 'INVALID_STATUS_TRANSITION';

    public const IDEMPOTENCY_KEY_INVALID = 'IDEMPOTENCY_KEY_INVALID';

    public const IDEMPOTENCY_KEY_REUSED = 'IDEMPOTENCY_KEY_REUSED';

    public const IDEMPOTENCY_REQUEST_IN_PROGRESS = 'IDEMPOTENCY_REQUEST_IN_PROGRESS';

    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    public const INTERNAL_ERROR = 'INTERNAL_ERROR';
}
