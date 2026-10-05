<?php

namespace App\Modules\Booking\Models;

use App\Modules\Booking\Enums\OrderItemStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One priced line of an order (spec §7.5). Prices are snapshots taken at
 * booking and never recomputed. Package lines carry the price; their child
 * test lines carry 0 and point to the parent.
 *
 * @property string $id
 * @property string $order_id
 * @property string|null $test_id
 * @property string|null $package_id
 * @property string|null $parent_item_id
 * @property string|null $recollection_of_item_id set on a free redraw line (spec §5.4)
 * @property string|null $processing_branch_id
 * @property Money $mrp_price
 * @property Money $partner_price
 * @property Money $discount
 * @property string|null $discount_reason
 * @property Money $net_price
 * @property CarbonImmutable|null $due_at
 * @property OrderItemStatus $status
 */
final class OrderItem extends BaseModel
{
    protected function casts(): array
    {
        return [
            'mrp_price' => MoneyCast::class,
            'partner_price' => MoneyCast::class,
            'discount' => MoneyCast::class,
            'net_price' => MoneyCast::class,
            'due_at' => 'immutable_datetime',
            'status' => OrderItemStatus::class,
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
