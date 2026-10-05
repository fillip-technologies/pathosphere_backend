<?php

namespace App\Modules\Samples\Listeners;

use App\Modules\Samples\Events\SampleRejected;
use App\Modules\Samples\Models\Sample;
use App\Modules\Samples\Services\SampleAlerts;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Tells the collecting branch and the patient that a redraw is needed (spec §9 SampleRejected). */
final class NotifySampleRejection implements ShouldQueue
{
    public function __construct(private readonly SampleAlerts $alerts) {}

    public function handle(SampleRejected $event): void
    {
        WithSystemScope::run($event->organizationId, function () use ($event): void {
            $this->alerts->sampleRejected(
                Sample::query()->findOrFail($event->sampleId),
                Sample::query()->findOrFail($event->redrawSampleId),
            );
        });
    }
}
