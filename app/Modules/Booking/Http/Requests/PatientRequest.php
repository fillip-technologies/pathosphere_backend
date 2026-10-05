<?php

namespace App\Modules\Booking\Http\Requests;

use App\Modules\Shared\Enums\Gender;
use App\Modules\Shared\Http\Validation\Formats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Register (POST) or correct (PATCH) a patient. Registration records the
 * consent notice shown (spec §10 DPDP); either a date of birth or an age is
 * required.
 */
final class PatientRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'salutation' => ['sometimes', 'nullable', 'string', 'max:10'],
            'name' => [$required, 'string', 'max:150'],
            'dob' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today', $creating ? 'required_without:age_years' : 'nullable'],
            'age_years' => ['sometimes', 'nullable', 'integer', 'between:0,130', $creating ? 'required_without:dob' : 'nullable'],
            'gender' => [$required, Rule::enum(Gender::class)],
            'phone' => [$required, Formats::PHONE],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'pincode' => ['sometimes', 'nullable', Formats::PINCODE],
            'registered_branch_id' => ['sometimes', 'uuid'],
            'guardian_patient_id' => ['sometimes', 'nullable', 'uuid', 'exists:patients,id'],
            'consent_notice_version' => [$required, 'string', 'max:20'],
            'whatsapp_opt_in' => ['sometimes', 'boolean'],
        ];
    }
}
