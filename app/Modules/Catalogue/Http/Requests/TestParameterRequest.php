<?php

namespace App\Modules\Catalogue\Http\Requests;

use App\Modules\Catalogue\Enums\ResultType;
use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Shared\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A parameter and, optionally, its full set of reference ranges. Option
 * parameters need their options; calculated ones need a formula.
 */
final class TestParameterRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $testId = $this->route('test') instanceof LabTest ? $this->route('test')->id : null;

        $rules = [
            'parameter_name' => [$required, 'string', 'max:150'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:30'],
            'result_type' => [$required, Rule::enum(ResultType::class)],
            'decimal_places' => ['sometimes', 'nullable', 'integer', 'between:0,4'],
            'options' => ['required_if:result_type,option', 'nullable', 'array', 'min:2'],
            'options.*' => ['string', 'max:100', 'distinct'],
            'formula' => ['required_if:result_type,calculated', 'nullable', 'string', 'max:500'],
            'display_order' => ['sometimes', 'integer', 'between:0,1000'],
            'reference_ranges' => ['sometimes', 'array', 'max:50'],
            'reference_ranges.*.gender' => ['nullable', Rule::enum(Gender::class)],
            'reference_ranges.*.age_min_days' => ['nullable', 'integer', 'min:0'],
            'reference_ranges.*.age_max_days' => ['nullable', 'integer', 'min:0', 'gte:reference_ranges.*.age_min_days'],
            'reference_ranges.*.ref_low' => ['nullable', 'decimal:0,4'],
            'reference_ranges.*.ref_high' => ['nullable', 'decimal:0,4'],
            'reference_ranges.*.critical_low' => ['nullable', 'decimal:0,4'],
            'reference_ranges.*.critical_high' => ['nullable', 'decimal:0,4'],
            'reference_ranges.*.display_text' => ['nullable', 'string', 'max:100'],
        ];

        if ($creating) {
            $rules['code'] = ['required', 'string', 'max:30', Rule::unique('test_parameters', 'code')->where('test_id', $testId)];
        }

        return $rules;
    }
}
