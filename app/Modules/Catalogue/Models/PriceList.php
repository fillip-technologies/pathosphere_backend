<?php

namespace App\Modules\Catalogue\Models;

use App\Modules\Catalogue\Enums\PriceListType;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * MRP, partner or client price list (spec §7.4). Endpoints that expose
 * prices are limited to manage_price_lists.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property PriceListType $list_type
 * @property bool $is_default_mrp
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property bool $is_active
 */
final class PriceList extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;
    use SoftDeletes;

    protected $hidden = ['default_mrp_flag'];

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['is_default_mrp' => false, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'list_type' => PriceListType::class,
            'is_default_mrp' => 'boolean',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(visibleToWholeOrganization: true);
    }

    /** @return HasMany<PriceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    /**
     * Active and inside its validity window on the given day.
     *
     * @param  Builder<PriceList>  $query
     */
    public function scopeInEffectOn(Builder $query, CarbonImmutable $day): void
    {
        $query->where('is_active', true)
            ->whereDate('valid_from', '<=', $day)
            ->where(fn (Builder $inner) => $inner->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day));
    }
}
