<?php

namespace App\Modules\Samples\Models;

use App\Modules\Samples\Enums\InventoryCategory;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;

/**
 * One batch of a reagent, tube or kit at a branch (spec §7.7). Quantities
 * change through stock transfers or a stock count by the branch.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $item_code
 * @property string $name
 * @property InventoryCategory $category
 * @property string $unit
 * @property string $quantity
 * @property string|null $reorder_level
 * @property string $batch_no
 * @property CarbonImmutable|null $expiry_date
 */
final class InventoryItem extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    protected function casts(): array
    {
        return [
            'category' => InventoryCategory::class,
            // Exact decimal strings ("12.50"), never float.
            'quantity' => 'decimal:2',
            'reorder_level' => 'decimal:2',
            'expiry_date' => 'immutable_date',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(branch: 'branch_id');
    }
}
