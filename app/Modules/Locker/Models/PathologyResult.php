<?php

namespace App\Modules\Locker\Models;

use App\Modules\Shared\Models\BaseModel;

/**
 * A copy of one final lab result (spec §7.11); powers trend charts.
 *
 * @property string $id
 * @property string $pathology_report_id
 * @property string $test_code
 * @property string $test_name
 * @property string $parameter_code
 * @property string $parameter_name
 * @property string|null $value
 * @property string|null $value_numeric
 * @property string|null $unit
 * @property string|null $reference_range
 * @property string|null $flag
 */
final class PathologyResult extends BaseModel
{
    public function recordsActor(): bool
    {
        return false;
    }
}
