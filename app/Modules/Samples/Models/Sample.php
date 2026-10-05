<?php

namespace App\Modules\Samples\Models;

use App\Modules\Samples\Enums\SampleStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One physical container (spec §7.6). Transaction table: never deleted; a
 * rejected sample stays as history and its redraw points back to it.
 *
 * Seen by the branch that collected it, the lab that tests it (spec §4
 * special rule) and the branch holding it now, e.g. a hub lab forwarding it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $order_id
 * @property string $barcode
 * @property string $sample_type
 * @property string $container_type
 * @property string $collected_branch_id
 * @property string $processing_branch_id
 * @property string $current_branch_id
 * @property string|null $collected_by
 * @property CarbonImmutable|null $collection_datetime
 * @property CarbonImmutable|null $stable_until
 * @property CarbonImmutable|null $received_at
 * @property string|null $received_by
 * @property SampleStatus $status
 * @property string|null $rejection_reason
 * @property string|null $rejection_note
 * @property string|null $recollection_of_id
 * @property string|null $storage_location
 * @property CarbonImmutable|null $discard_after
 * @property CarbonImmutable|null $delay_alerted_at
 * @property Collection<int, SampleOrderItem> $orderItems
 * @property Collection<int, ManifestItem> $manifestItems
 */
final class Sample extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    protected function casts(): array
    {
        return [
            'collection_datetime' => 'immutable_datetime',
            'stable_until' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'status' => SampleStatus::class,
            'discard_after' => 'immutable_date',
            'delay_alerted_at' => 'immutable_datetime',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(
            branch: 'collected_branch_id',
            processingBranch: 'processing_branch_id',
            otherBranches: ['current_branch_id'],
        );
    }

    /** @return HasMany<SampleOrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(SampleOrderItem::class);
    }

    /** @return HasMany<ManifestItem, $this> */
    public function manifestItems(): HasMany
    {
        return $this->hasMany(ManifestItem::class);
    }

    /** @return list<string> */
    public function orderItemIds(): array
    {
        return $this->orderItems()->pluck('order_item_id')->all();
    }
}
