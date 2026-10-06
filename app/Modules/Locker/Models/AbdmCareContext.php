<?php

namespace App\Modules\Locker\Models;

use App\Modules\Locker\Enums\CareContextLinkStatus;
use App\Modules\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One released report version known to ABDM as a care context of the
 * patient's ABHA (spec §5.7 M2). The reference shared with ABDM is the
 * report's ID. Not network-scoped: reached only by ABDM callbacks and the
 * system jobs that answer them.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $patient_id
 * @property string $report_id
 * @property string $medical_record_id
 * @property string $hip_branch_id
 * @property string $care_context_reference
 * @property string $display_name
 * @property string $hi_type
 * @property CareContextLinkStatus $link_status
 * @property CarbonImmutable|null $linked_at
 * @property string|null $link_request_id
 * @property int $link_attempts
 * @property string|null $error_code
 * @property MedicalRecord $record
 */
final class AbdmCareContext extends BaseModel
{
    public const HI_TYPE = 'DiagnosticReport';

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['link_attempts' => 0];

    protected function casts(): array
    {
        return [
            'link_status' => CareContextLinkStatus::class,
            'linked_at' => 'immutable_datetime',
            'link_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<MedicalRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }
}
