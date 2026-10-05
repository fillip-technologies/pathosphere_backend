<?php

namespace App\Modules\Network\Http\Requests;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Shared\Http\Validation\Formats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateOrganizationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'legal_name' => ['sometimes', 'string', 'max:200'],
            'gstin' => ['sometimes', 'nullable', Formats::GSTIN, Rule::unique('organizations', 'gstin')->where(fn ($query) => $query->where('id', '!=', app(StaffContext::class)->user()->organization_id))],
            'pan' => ['sometimes', 'nullable', Formats::PAN],
            'cin' => ['sometimes', 'nullable', 'string', 'max:21'],
            'hq_address' => ['sometimes', 'string', 'max:1000'],
            'settings' => ['sometimes', 'array'],
        ];
    }
}
