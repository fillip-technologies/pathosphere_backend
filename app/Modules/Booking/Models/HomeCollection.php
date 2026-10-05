<?php

namespace App\Modules\Booking\Models;

use App\Modules\Booking\Enums\HomeCollectionStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phlebotomist's visit to collect samples at home (spec §5.3, §7.5).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $order_id
 * @property string $branch_id
 * @property string|null $phlebotomist_id
 * @property string $address
 * @property string $pincode
 * @property string|null $latitude
 * @property string|null $longitude
 * @property CarbonImmutable $slot_start
 * @property CarbonImmutable $slot_end
 * @property Money $collection_charge
 * @property HomeCollectionStatus $status
 * @property string|null $status_note
 * @property CarbonImmutable|null $collected_at
 * @property string|null $collected_lat
 * @property string|null $collected_lng
 * @property Order $order
 */
final class HomeCollection extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    protected function casts(): array
    {
        return [
            'slot_start' => 'immutable_datetime',
            'slot_end' => 'immutable_datetime',
            'collection_charge' => MoneyCast::class,
            'status' => HomeCollectionStatus::class,
            'collected_at' => 'immutable_datetime',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(branch: 'branch_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
