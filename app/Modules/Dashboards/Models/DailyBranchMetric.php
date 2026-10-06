<?php

namespace App\Modules\Dashboards\Models;

use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;

/**
 * One branch's day, summarised overnight for dashboards.
 *
 * @property string $id
 * @property string $organization_id
 * @property CarbonImmutable $metric_date
 * @property string $branch_id
 * @property string $region_id
 * @property string|null $franchise_id
 * @property int $orders_booked
 * @property int $orders_cancelled
 * @property int $tests_ordered
 * @property Money $gross_billing
 * @property Money $collected
 * @property int $samples_rejected
 * @property int $reports_released
 * @property int $tat_breaches
 */
final class DailyBranchMetric extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    /** The counters, in the order dashboards show them. */
    public const COUNTERS = ['orders_booked', 'orders_cancelled', 'tests_ordered', 'samples_rejected', 'reports_released', 'tat_breaches'];

    public const AMOUNTS = ['gross_billing', 'collected'];

    protected function casts(): array
    {
        return [
            'metric_date' => 'immutable_date',
            'gross_billing' => MoneyCast::class,
            'collected' => MoneyCast::class,
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(region: 'region_id', franchise: 'franchise_id', branch: 'branch_id');
    }

    /** Computed by the system; no actor columns. */
    public function recordsActor(): bool
    {
        return false;
    }
}
