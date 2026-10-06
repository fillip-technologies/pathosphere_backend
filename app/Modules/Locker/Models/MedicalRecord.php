<?php

namespace App\Modules\Locker\Models;

use App\Modules\Locker\Enums\RecordSource;
use App\Modules\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The common index of every health record (spec §7.11); the timeline sorts
 * on `record_date`. Not network-scoped: a patient reaches only their own
 * records, and staff never read the locker.
 *
 * @property string $id
 * @property string $patient_id
 * @property string $category_id
 * @property RecordSource $source
 * @property CarbonImmutable $record_date
 * @property string $title
 * @property string|null $provider_facility
 * @property string|null $superseded_by_id
 * @property CarbonImmutable $created_at
 * @property RecordCategory $category
 * @property PathologyReport|null $pathologyReport
 * @property Collection<int, MedicalDocument> $documents
 */
final class MedicalRecord extends BaseModel
{
    protected function casts(): array
    {
        return ['source' => RecordSource::class, 'record_date' => 'immutable_date'];
    }

    /** @return BelongsTo<RecordCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RecordCategory::class);
    }

    /** @return HasOne<PathologyReport, $this> */
    public function pathologyReport(): HasOne
    {
        return $this->hasOne(PathologyReport::class);
    }

    /** @return HasMany<MedicalDocument, $this> every version, newest first */
    public function documents(): HasMany
    {
        return $this->hasMany(MedicalDocument::class)->orderByDesc('version');
    }

    /**
     * Records of these patients that are still current (not replaced by a
     * corrected report).
     *
     * @param  Builder<MedicalRecord>  $query
     * @param  list<string>  $patientIds
     */
    public function scopeCurrentFor(Builder $query, array $patientIds): void
    {
        $query->whereIn('patient_id', $patientIds)->whereNull('superseded_by_id');
    }
}
