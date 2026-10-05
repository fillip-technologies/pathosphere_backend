<?php

namespace App\Modules\Catalogue\Http\Requests;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Shared\Http\Validation\Formats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PackageRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'name' => [$required, 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'test_ids' => [$required, 'array', 'min:2', 'max:200'],
            'test_ids.*' => ['uuid', 'distinct'],
        ];

        if ($creating) {
            $rules['code'] = [
                'required', 'string', 'max:20', Formats::CODE,
                Rule::unique('packages', 'code')->where(fn ($query) => $query->where('organization_id', app(StaffContext::class)->user()->organization_id)),
            ];
        }

        return $rules;
    }
}
