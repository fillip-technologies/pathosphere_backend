<?php

namespace App\Modules\Shared\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Base for every business model: time-ordered UUID keys (spec §6.2),
 * datetime(6) columns stored in UTC (§6.3), actor columns (§6.5) and
 * ISO-8601 UTC dates in API output.
 *
 * Soft deletes are opted into per model with SoftDeletes, because only master
 * and people tables may use them (§6.4).
 *
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
abstract class BaseModel extends Model
{
    use HasUuids;
    use RecordsActor;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** Mass assignment is closed by default; services set attributes explicitly. */
    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by'];

    protected function serializeDate(DateTimeInterface $date): string
    {
        return Carbon::instance($date)->utc()->toIso8601ZuluString();
    }
}
