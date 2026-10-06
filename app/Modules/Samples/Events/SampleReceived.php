<?php

namespace App\Modules\Samples\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Raised when a lab accepts a sample: scanned in from a manifest, or
 * accessioned where it was drawn. The lab that tests it opens its work.
 */
final class SampleReceived implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $sampleId,
        public readonly string $organizationId,
    ) {}
}
