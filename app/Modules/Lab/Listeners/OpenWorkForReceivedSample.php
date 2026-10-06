<?php

namespace App\Modules\Lab\Listeners;

use App\Modules\Lab\Services\WorklistService;
use App\Modules\Samples\Events\SampleReceived;
use App\Modules\Shared\Jobs\WithSystemScope;

/**
 * A lab accepted a sample: its tests join that lab's worklist. Runs right
 * after the scan commits, so the bench sees the work immediately.
 */
final class OpenWorkForReceivedSample
{
    public function __construct(private readonly WorklistService $worklist) {}

    public function handle(SampleReceived $event): void
    {
        WithSystemScope::run($event->organizationId, fn () => $this->worklist->openForSample($event->organizationId, $event->sampleId));
    }
}
