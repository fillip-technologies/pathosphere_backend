<?php

namespace App\Modules\Samples\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Raised when scanning changes a manifest's receipt status (spec §9). */
final class ManifestReceived implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $manifestId,
        public readonly string $organizationId,
    ) {}
}
