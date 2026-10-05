<?php

namespace App\Modules\Booking\Http\Requests;

use App\Modules\Booking\Enums\ReportDelivery;
use App\Modules\Shared\Http\Validation\Formats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DoctorRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:150'],
            'registration_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:100'],
            'clinic_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', Formats::PHONE],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:150'],
            'report_delivery' => ['sometimes', Rule::enum(ReportDelivery::class)],
        ];
    }
}
