<?php

namespace App\Modules\Locker\Models;

use App\Modules\Locker\Enums\RecordSource;
use App\Modules\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;

/**
 * Where a record fetched from ABDM or DigiLocker came from (spec §7.11).
 * Filled from Phase 8 (ABDM M3) and Phase 9 (DigiLocker).
 *
 * @property string $id
 * @property string $patient_id
 * @property string $medical_record_id
 * @property RecordSource $source
 * @property string $external_id
 * @property string|null $care_context_ref
 * @property CarbonImmutable $fetched_at
 */
final class ExternalHealthRecord extends BaseModel
{
    protected function casts(): array
    {
        return ['source' => RecordSource::class, 'fetched_at' => 'immutable_datetime'];
    }
}
