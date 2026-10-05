<?php

namespace App\Modules\Shared\Context;

/**
 * Holds the actor for the current request or job. Bound as a scoped
 * singleton, so it is reset between requests and queue jobs.
 */
final class CurrentActor
{
    private ?Actor $actor = null;

    public function set(Actor $actor): void
    {
        $this->actor = $actor;
    }

    public function get(): ?Actor
    {
        return $this->actor;
    }

    public function userId(): ?string
    {
        return $this->actor?->userId;
    }

    public function organizationId(): ?string
    {
        return $this->actor?->organizationId;
    }

    /**
     * Run a callback as another actor and restore the previous one afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Actor $actor, callable $callback): mixed
    {
        $previous = $this->actor;
        $this->actor = $actor;

        try {
            return $callback();
        } finally {
            $this->actor = $previous;
        }
    }
}
