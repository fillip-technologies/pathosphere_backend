<?php

namespace App\Modules\Locker\Http\Resources;

use App\Modules\Booking\Services\PatientProfile;
use App\Modules\Locker\Models\RecordShare;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A record as the person it was shared with sees it: the record, who it is
 * about (identity only, no contact details) and until when it is shared.
 *
 * @mixin RecordShare
 */
final class SharedRecordResource extends JsonResource
{
    public function __construct(RecordShare $share, private readonly ?PatientProfile $patient, private readonly bool $withResults)
    {
        parent::__construct($share);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $record = $this->withResults
            ? MedicalRecordDetailResource::make($this->record)->toArray($request)
            : MedicalRecordResource::make($this->record)->toArray($request);
        unset($record['patient_id'], $record['superseded_by_id']);

        return [
            'record' => $record,
            'patient' => $this->patient === null ? null : [
                'name' => $this->patient->name,
                'uhid' => $this->patient->uhid,
                'gender' => $this->patient->gender,
                'age_years' => $this->patient->ageYears,
            ],
            'share' => [
                'purpose' => $this->consent->purpose,
                'shared_at' => $this->consent->granted_at?->toIso8601ZuluString(),
                'expires_at' => $this->expires_at->toIso8601ZuluString(),
            ],
        ];
    }
}
