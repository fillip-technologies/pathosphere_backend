<?php

namespace App\Modules\Lab\Http\Requests;

use App\Modules\Catalogue\Enums\SigningDiscipline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A new signatory with their signature image (multipart, PNG). */
final class StoreSignatoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid'],
            'branch_id' => ['required', 'uuid'],
            'department_id' => ['required', 'uuid'],
            'signing_discipline' => ['required', Rule::enum(SigningDiscipline::class)],
            'qualification' => ['required', 'string', 'max:100'],
            'council_name' => ['required', 'string', 'max:100'],
            'registration_no' => ['required', 'string', 'max:50'],
            'hpr_id' => ['nullable', 'string', 'max:50'],
            'valid_till' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'signature_image' => ['required', 'file', 'mimes:png', 'max:512'],
        ];
    }
}
