<?php

namespace App\Modules\Booking\Http\Resources;

use App\Modules\Booking\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full patient record, for the detail view.
 *
 * @mixin Patient
 */
final class PatientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uhid' => $this->uhid,
            'salutation' => $this->salutation,
            'name' => $this->name,
            'dob' => $this->dob?->toDateString(),
            'age_years' => $this->ageInYears(),
            'gender' => $this->gender,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'pincode' => $this->pincode,
            'registered_branch_id' => $this->registered_branch_id,
            'guardian_patient_id' => $this->guardian_patient_id,
            'merged_into_id' => $this->merged_into_id,
            'whatsapp_opted_in' => $this->whatsapp_opted_in_at !== null,
            'abha' => [
                'status' => $this->abha_status,
                'number' => $this->abha_number,
                'address' => $this->abha_address,
                'kyc_verified' => $this->abha_kyc_verified,
                'linked_at' => $this->abha_linked_at?->toIso8601ZuluString(),
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
