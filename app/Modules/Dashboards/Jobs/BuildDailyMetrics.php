<?php

namespace App\Modules\Dashboards\Jobs;

use App\Modules\Dashboards\Services\DailyMetricsBuilder;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Jobs\WithSystemScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Nightly dashboard summary (spec §11 observability 4): yesterday by default. */
final class BuildDailyMetrics implements ShouldQueue
{
    use Queueable;

    /** @param  string|null  $date  business date (Y-m-d) to summarise */
    public function __construct(public readonly ?string $date = null)
    {
        $this->onQueue('low');
    }

    public function handle(NetworkDirectory $network, DailyMetricsBuilder $builder): void
    {
        $date = CarbonImmutable::parse($this->date ?? CarbonImmutable::now('Asia/Kolkata')->subDay()->toDateString());

        foreach ($network->organizationIds() as $organizationId) {
            WithSystemScope::run($organizationId, fn () => $builder->build($organizationId, $date));
        }
    }
}
