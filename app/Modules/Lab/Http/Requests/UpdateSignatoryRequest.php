<?php

namespace App\Modules\Lab\Http\Requests;

use App\Modules\Catalogue\Enums\SigningDiscipline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Registration details and validity; who, where and which department never change. */
final class UpdateSignatoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'signing_discipline' => ['sometimes', Rule::enum(SigningDiscipline::class)],
            'qualification' => ['sometimes', 'string', 'max:100'],
            'council_name' => ['sometimes', 'string', 'max:100'],
            'registration_no' => ['sometimes', 'string', 'max:50'],
            'hpr_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'valid_till' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
