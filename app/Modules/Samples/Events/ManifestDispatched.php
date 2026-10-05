<?php

namespace App\Modules\Samples\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Raised when a runner dispatches a manifest (spec §9): the receiving lab is told. */
final class ManifestDispatched implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $manifestId,
        public readonly string $organizationId,
    ) {}
}
