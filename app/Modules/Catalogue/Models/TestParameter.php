<?php

namespace App\Modules\Catalogue\Models;

use App\Modules\Catalogue\Enums\ResultType;
use App\Modules\Shared\Models\BaseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One analyte reported for a test (spec §7.4). Reached through its test, which
 * carries the organization scope.
 *
 * @property string $id
 * @property string $test_id
 * @property string $parameter_name
 * @property string $code
 * @property string|null $loinc_code
 * @property string|null $unit
 * @property ResultType $result_type
 * @property int|null $decimal_places
 * @property list<string>|null $options
 * @property string|null $formula
 * @property int $display_order
 * @property Collection<int, ReferenceRange> $referenceRanges
 */
final class TestParameter extends BaseModel
{
    use SoftDeletes;

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['display_order' => 0];

    protected function casts(): array
    {
        return [
            'result_type' => ResultType::class,
            'decimal_places' => 'integer',
            'options' => 'array',
            'display_order' => 'integer',
        ];
    }

    /** @return BelongsTo<LabTest, $this> */
    public function test(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'test_id');
    }

    /** @return HasMany<ReferenceRange, $this> */
    public function referenceRanges(): HasMany
    {
        return $this->hasMany(ReferenceRange::class);
    }
}
