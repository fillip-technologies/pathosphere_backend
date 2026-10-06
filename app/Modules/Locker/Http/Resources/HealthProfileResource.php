<?php

namespace App\Modules\Locker\Http\Resources;

use App\Modules\Locker\Models\PatientHealthProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PatientHealthProfile */
final class HealthProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'patient_id' => $this->patient_id,
            'blood_group' => $this->blood_group,
            'allergies' => $this->allergies,
            'chronic_conditions' => $this->chronic_conditions,
            'health_summary' => $this->health_summary,
            'updated_at' => $this->exists ? $this->updated_at : null,
        ];
    }
}
