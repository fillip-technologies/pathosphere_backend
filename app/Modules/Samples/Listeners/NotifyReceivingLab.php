<?php

namespace App\Modules\Samples\Listeners;

use App\Modules\Samples\Events\ManifestDispatched;
use App\Modules\Samples\Models\Manifest;
use App\Modules\Samples\Services\SampleAlerts;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Contracts\Queue\ShouldQueue;

/** The receiving lab learns what is on its way (spec §9 ManifestDispatched). */
final class NotifyReceivingLab implements ShouldQueue
{
    public function __construct(private readonly SampleAlerts $alerts) {}

    public function handle(ManifestDispatched $event): void
    {
        WithSystemScope::run($event->organizationId, function () use ($event): void {
            $manifest = Manifest::query()->withCount('items')->findOrFail($event->manifestId);
            $this->alerts->manifestDispatched($manifest, (int) $manifest->getAttribute('items_count'));
        });
    }
}
