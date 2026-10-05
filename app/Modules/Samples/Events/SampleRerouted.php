<?php

namespace App\Modules\Samples\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Raised when a lab sends a sample on to another lab (spec §5.4 step 6). */
final class SampleRerouted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $sampleId,
        public readonly string $organizationId,
    ) {}
}
