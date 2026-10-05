<?php

namespace App\Modules\Samples\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Raised when a lab rejects a sample (spec §9). The collecting branch and the
 * patient are told; Phase 6 listens here to reverse partner charges when the
 * agreement says so.
 */
final class SampleRejected implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $sampleId,
        public readonly string $redrawSampleId,
        public readonly string $organizationId,
    ) {}
}
