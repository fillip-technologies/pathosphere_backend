<?php

namespace App\Modules\Catalogue\Models;

use App\Modules\Catalogue\Enums\StorageTemperature;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An orderable lab test (table `tests`, spec §7.4). Named LabTest so it is
 * never confused with automated tests.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $department_id
 * @property string $code
 * @property string $name
 * @property string|null $short_name
 * @property string|null $loinc_code
 * @property string $sample_type
 * @property string $container_type
 * @property string|null $sample_volume_ml
 * @property StorageTemperature|null $storage_temp
 * @property int|null $stability_hours
 * @property int $tat_hours
 * @property string|null $method
 * @property string|null $patient_instructions
 * @property Money $base_price
 * @property bool $is_outsourced_only
 * @property bool $is_active
 * @property Department $department
 * @property Collection<int, TestParameter> $parameters
 */
final class LabTest extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;
    use SoftDeletes;

    protected $table = 'tests';

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['is_outsourced_only' => false, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'storage_temp' => StorageTemperature::class,
            'stability_hours' => 'integer',
            'tat_hours' => 'integer',
            'base_price' => MoneyCast::class,
            'is_outsourced_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(visibleToWholeOrganization: true);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<TestParameter, $this> */
    public function parameters(): HasMany
    {
        return $this->hasMany(TestParameter::class, 'test_id')->orderBy('display_order');
    }
}
