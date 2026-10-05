<?php

namespace App\Modules\Catalogue\Models;

use App\Modules\Shared\Enums\Gender;
use App\Modules\Shared\Models\BaseModel;

/**
 * Normal and critical limits for one parameter, by gender and age
 * (spec §7.4 test_reference_ranges). A null bound means "no limit".
 *
 * @property string $id
 * @property string $test_parameter_id
 * @property Gender|null $gender
 * @property int|null $age_min_days
 * @property int|null $age_max_days
 * @property string|null $ref_low
 * @property string|null $ref_high
 * @property string|null $critical_low
 * @property string|null $critical_high
 * @property string|null $display_text
 */
final class ReferenceRange extends BaseModel
{
    protected $table = 'test_reference_ranges';

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'age_min_days' => 'integer',
            'age_max_days' => 'integer',
        ];
    }
}
