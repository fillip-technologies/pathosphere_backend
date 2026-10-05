<?php

namespace App\Modules\Samples\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Raised when a container is drawn (spec §9): it joins the open manifest to its lab. */
final class SampleCollected implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $sampleId,
        public readonly string $organizationId,
    ) {}
}
