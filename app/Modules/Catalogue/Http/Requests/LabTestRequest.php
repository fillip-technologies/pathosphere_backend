<?php

namespace App\Modules\Catalogue\Http\Requests;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Catalogue\Enums\StorageTemperature;
use App\Modules\Shared\Http\Validation\Formats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST) and partial update (PATCH) of a test. The code is fixed at creation. */
final class LabTestRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'department_id' => [$required, 'uuid'],
            'name' => [$required, 'string', 'max:200'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:50'],
            'loinc_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'sample_type' => [$required, 'string', 'max:50'],
            'container_type' => [$required, 'string', 'max:50'],
            'sample_volume_ml' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
            'storage_temp' => ['sometimes', 'nullable', Rule::enum(StorageTemperature::class)],
            'stability_hours' => ['sometimes', 'nullable', 'integer', 'between:1,8760'],
            'tat_hours' => [$required, 'integer', 'between:1,8760'],
            'method' => ['sometimes', 'nullable', 'string', 'max:100'],
            'patient_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'base_price' => [$required, 'decimal:0,2', 'min:0'],
            'is_outsourced_only' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($creating) {
            $rules['code'] = [
                'required', 'string', 'max:20', Formats::CODE,
                Rule::unique('tests', 'code')->where(fn ($query) => $query->where('organization_id', app(StaffContext::class)->user()->organization_id)),
            ];
        }

        return $rules;
    }
}
