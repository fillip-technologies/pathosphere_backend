<?php

namespace App\Modules\Booking\Http\Resources;

use App\Modules\Booking\Models\Doctor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Doctor */
final class DoctorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'registration_no' => $this->registration_no,
            'specialization' => $this->specialization,
            'clinic_name' => $this->clinic_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'report_delivery' => $this->report_delivery,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
