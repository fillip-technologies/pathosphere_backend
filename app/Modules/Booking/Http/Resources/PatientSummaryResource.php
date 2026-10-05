<?php

namespace App\Modules\Booking\Http\Resources;

use App\Modules\Booking\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Search results: phone masked to the last 4 digits (spec §10.2).
 *
 * @mixin Patient
 */
final class PatientSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uhid' => $this->uhid,
            'name' => $this->name,
            'age_years' => $this->ageInYears(),
            'gender' => $this->gender,
            'phone_masked' => $this->maskedPhone(),
            'abha_linked' => $this->abha_status->value === 'linked',
        ];
    }
}
