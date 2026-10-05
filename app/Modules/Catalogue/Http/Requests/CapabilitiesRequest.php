<?php

namespace App\Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The complete set of tests a lab can run (PUT replaces all). */
final class CapabilitiesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'capabilities' => ['present', 'array', 'max:5000'],
            'capabilities.*.test_id' => ['required', 'uuid', 'distinct'],
            'capabilities.*.is_active' => ['sometimes', 'boolean'],
            'capabilities.*.daily_capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /** @return list<array{test_id: string, is_active?: bool, daily_capacity?: int|null}> */
    public function capabilities(): array
    {
        return array_values($this->validated('capabilities'));
    }
}
