<?php

namespace App\Modules\Auth\Services;

/**
 * A half-finished sign-in: the password was right and an authenticator code
 * is still needed.
 */
final class MfaChallenge
{
    public function __construct(
        public readonly string $accountId,
        public readonly int $failedAttempts,
        public readonly ?string $ipAddress,
        public readonly ?string $deviceInfo,
    ) {}

    public function withFailedAttempt(): self
    {
        return new self($this->accountId, $this->failedAttempts + 1, $this->ipAddress, $this->deviceInfo);
    }
}
