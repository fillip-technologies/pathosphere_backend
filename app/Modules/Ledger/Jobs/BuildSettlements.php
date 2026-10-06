<?php

namespace App\Modules\Ledger\Jobs;

use App\Modules\Ledger\Services\SettlementBuilder;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Jobs\WithSystemScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Settlement builder (spec §9: daily 02:00, acts on cycle end dates).
 * Every partner whose cycle closed gets a settlement; partners with one still
 * open are picked up once it is settled. Running twice builds nothing new.
 */
final class BuildSettlements implements ShouldQueue
{
    use Queueable;

    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    /** @param  string|null  $today  business date (Y-m-d) to build as of; today by default */
    public function __construct(public readonly ?string $today = null) {}

    public function handle(SettlementBuilder $builder, NetworkDirectory $network): void
    {
        $today = CarbonImmutable::parse($this->today ?? CarbonImmutable::now(self::BUSINESS_TIMEZONE)->toDateString());

        foreach ($network->organizationIds() as $organizationId) {
            WithSystemScope::run($organizationId, fn () => $builder->buildDue($organizationId, $today));
        }
    }
}
