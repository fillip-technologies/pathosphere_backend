<?php

namespace App\Modules\Samples\Jobs;

use App\Modules\Samples\Enums\ManifestItemCondition;
use App\Modules\Samples\Enums\ManifestStatus;
use App\Modules\Samples\Models\Manifest;
use App\Modules\Samples\Services\SampleAlerts;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Alerts both ends of a route about samples still unscanned after the grace
 * period. Idempotent: a manifest is flagged once, and a manifest received in
 * full meanwhile is left alone.
 */
final class FlagMissingSamples implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $manifestId,
        public readonly string $organizationId,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new WithSystemScope($this->organizationId)];
    }

    public function handle(SampleAlerts $alerts): void
    {
        $manifest = Manifest::query()->find($this->manifestId);

        if ($manifest === null || $manifest->status !== ManifestStatus::PartiallyReceived || $manifest->missing_flagged_at !== null) {
            return;
        }

        $missingCount = $manifest->items()->where('condition', ManifestItemCondition::Pending)->count();

        if ($missingCount === 0) {
            return;
        }

        $manifest->update(['missing_flagged_at' => now()]);
        $alerts->samplesMissing($manifest, $missingCount);
    }
}
