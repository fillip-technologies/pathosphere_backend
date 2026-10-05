<?php

namespace App\Modules\Auth\Services;

/**
 * A half-finished sign-in: the password was right and an authenticator code
 * is still needed. `pendingSecret` is set while a user is enrolling.
 */
final class MfaChallenge
{
    public function __construct(
        public readonly string $accountId,
        public readonly bool $isEnrollment,
        public readonly ?string $pendingSecret,
        public readonly int $failedAttempts,
        public readonly ?string $ipAddress,
        public readonly ?string $deviceInfo,
    ) {}

    public function withPendingSecret(string $secret): self
    {
        return new self($this->accountId, $this->isEnrollment, $secret, $this->failedAttempts, $this->ipAddress, $this->deviceInfo);
    }

    public function withFailedAttempt(): self
    {
        return new self($this->accountId, $this->isEnrollment, $this->pendingSecret, $this->failedAttempts + 1, $this->ipAddress, $this->deviceInfo);
    }
}
