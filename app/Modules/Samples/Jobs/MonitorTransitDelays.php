<?php

namespace App\Modules\Samples\Jobs;

use App\Modules\Samples\Enums\ManifestStatus;
use App\Modules\Samples\Enums\SampleStatus;
use App\Modules\Samples\Models\Manifest;
use App\Modules\Samples\Models\Sample;
use App\Modules\Samples\Services\SampleAlerts;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Transit delay monitor (spec §9, every 30 minutes): samples in transit past
 * their stability limit. One alert per manifest to both ends of the route;
 * each sample is alerted once.
 */
final class MonitorTransitDelays implements ShouldQueue
{
    use Queueable;

    /** @return list<object> */
    public function middleware(): array
    {
        return [new WithSystemScope(null)];
    }

    public function handle(SampleAlerts $alerts): void
    {
        $delayed = Sample::query()
            ->where('status', SampleStatus::InTransit)
            ->where('stable_until', '<', now())
            ->whereNull('delay_alerted_at')
            ->get();

        if ($delayed->isEmpty()) {
            return;
        }

        $manifests = Manifest::query()
            ->whereIn('status', [ManifestStatus::Dispatched, ManifestStatus::PartiallyReceived])
            ->whereHas('items', fn ($items) => $items->whereIn('sample_id', $delayed->pluck('id')))
            ->with(['items' => fn ($items) => $items->whereIn('sample_id', $delayed->pluck('id'))])
            ->get();

        foreach ($manifests as $manifest) {
            $sampleIds = $manifest->items->pluck('sample_id')->all();
            Sample::query()->whereKey($sampleIds)->update(['delay_alerted_at' => now()]);
            $alerts->samplesDelayed($manifest, count($sampleIds));
        }
    }
}
