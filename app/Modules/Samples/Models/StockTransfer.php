<?php

namespace App\Modules\Samples\Models;

use App\Modules\Samples\Enums\StockTransferStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;

/**
 * HQ or a lab supplying another branch with one batch of an item
 * (spec §7.7). Both ends see it; the sender dispatches, the receiver
 * receives. Cancelled, never deleted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $transfer_no
 * @property string $from_branch_id
 * @property string $to_branch_id
 * @property string $item_code
 * @property string $item_name
 * @property string $batch_no
 * @property string $quantity
 * @property Money $charge_amount
 * @property StockTransferStatus $status
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $received_at
 * @property string|null $cancelled_reason
 */
final class StockTransfer extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    /** Mirrors the column default, so new models report what the database stores. */
    protected $attributes = ['charge_amount' => '0.00'];

    protected function casts(): array
    {
        return [
            'charge_amount' => MoneyCast::class,
            'quantity' => 'decimal:2',
            'status' => StockTransferStatus::class,
            'dispatched_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(branch: 'from_branch_id', otherBranches: ['to_branch_id']);
    }
}
