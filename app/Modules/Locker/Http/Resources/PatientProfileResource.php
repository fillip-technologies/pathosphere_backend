<?php

namespace App\Modules\Locker\Http\Resources;

use App\Modules\Booking\Services\PatientProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PatientProfile */
final class PatientProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uhid' => $this->uhid,
            'name' => $this->name,
            'gender' => $this->gender,
            'dob' => $this->dob?->toDateString(),
            'age_years' => $this->ageYears,
            'phone' => $this->maskedPhone,
            'abha_status' => $this->abhaStatus,
        ];
    }
}
