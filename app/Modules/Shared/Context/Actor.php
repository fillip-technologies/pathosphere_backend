<?php

namespace App\Modules\Shared\Context;

/**
 * Who is performing the current work: a signed-in user, or the system itself
 * (queue jobs, scheduled tasks). Jobs always run as an explicit system actor,
 * never as "nobody" (spec §4.3).
 */
final class Actor
{
    private function __construct(
        public readonly ?string $userId,
        public readonly ?string $organizationId,
        public readonly bool $isSystem,
    ) {}

    public static function user(string $userId, string $organizationId): self
    {
        return new self($userId, $organizationId, false);
    }

    public static function system(?string $organizationId = null): self
    {
        return new self(null, $organizationId, true);
    }

    /**
     * A patient or doctor signed in through their own guard (spec §4: no
     * staff role). They are not rows in `users`, so actor columns stay null;
     * the health locker records who they are in record_access_logs.
     */
    public static function external(string $organizationId): self
    {
        return new self(null, $organizationId, false);
    }
}
