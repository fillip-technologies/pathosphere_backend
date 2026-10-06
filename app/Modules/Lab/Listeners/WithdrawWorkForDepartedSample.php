<?php

namespace App\Modules\Lab\Listeners;

use App\Modules\Lab\Services\WorklistService;
use App\Modules\Samples\Events\SampleRejected;
use App\Modules\Samples\Events\SampleRerouted;
use App\Modules\Shared\Jobs\WithSystemScope;

/** A sample re-routed onward or rejected leaves the lab's worklist (spec §5.4 steps 5–6). */
final class WithdrawWorkForDepartedSample
{
    public function __construct(private readonly WorklistService $worklist) {}

    public function handle(SampleRerouted|SampleRejected $event): void
    {
        WithSystemScope::run($event->organizationId, fn () => $this->worklist->withdrawForSample($event->organizationId, $event->sampleId));
    }
}
