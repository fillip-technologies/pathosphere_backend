<?php

namespace App\Modules\Lab\Http\Requests;

use App\Modules\Lab\Services\ResultInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Results for one test: a value per parameter code. Values are text as
 * reported; JSON numbers are accepted and read as text.
 */
final class EnterResultsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_item_id' => ['required', 'uuid'],
            'results' => ['required', 'array', 'min:1', 'max:100'],
            'results.*.parameter_code' => ['required', 'string', 'max:30'],
            'results.*.value' => ['required', 'string', 'max:255'],
            'results.*.comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $results = $this->input('results');

        if (! is_array($results)) {
            return;
        }

        $this->merge(['results' => array_map(
            fn ($result) => is_array($result) && (is_int($result['value'] ?? null) || is_float($result['value'] ?? null))
                ? ['value' => (string) $result['value']] + $result
                : $result,
            $results,
        )]);
    }

    /** @return list<ResultInput> */
    public function inputs(): array
    {
        return array_map(
            fn (array $result) => new ResultInput($result['parameter_code'], $result['value'], $result['comment'] ?? null),
            array_values($this->validated('results')),
        );
    }
}
