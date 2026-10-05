<?php

namespace App\Modules\Network\Models;

use App\Modules\Network\Enums\RegionType;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Database\Factories\Network\RegionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Zone / state / city node (spec §7.1).
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $parent_region_id
 * @property string $name
 * @property RegionType $region_type
 * @property CarbonImmutable $updated_at
 */
final class Region extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    /** @use HasFactory<RegionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return ['region_type' => RegionType::class];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(region: 'id');
    }

    /** @return BelongsTo<Region, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'parent_region_id');
    }

    /** @return HasMany<Region, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Region::class, 'parent_region_id');
    }

    protected static function newFactory(): RegionFactory
    {
        return RegionFactory::new();
    }
}
