<?php

namespace App\Modules\Samples\Listeners;

use App\Modules\Samples\Events\ManifestReceived;
use App\Modules\Samples\Jobs\FlagMissingSamples;

/**
 * Gives the receiving lab time to finish scanning, then flags what never
 * arrived (spec §9: missing samples flagged 2 hours after receipt).
 */
final class ScheduleMissingSampleCheck
{
    public function handle(ManifestReceived $event): void
    {
        FlagMissingSamples::dispatch($event->manifestId, $event->organizationId)
            ->delay(now()->addMinutes((int) config('pathology.samples.missing_after_minutes')));
    }
}
