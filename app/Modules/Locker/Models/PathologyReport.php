<?php

namespace App\Modules\Locker\Models;

use App\Modules\Shared\Models\BaseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bridge from a record to one released report version (spec §7.11),
 * created when the report is released.
 *
 * @property string $id
 * @property string $medical_record_id
 * @property string $report_id
 * @property string $lab_name
 * @property Collection<int, PathologyResult> $results
 */
final class PathologyReport extends BaseModel
{
    /** @return HasMany<PathologyResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(PathologyResult::class)->orderBy('id');
    }
}
