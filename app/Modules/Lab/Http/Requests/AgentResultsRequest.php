<?php

namespace App\Modules\Lab\Http\Requests;

use App\Modules\Lab\Services\AnalyserResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An upload from the interface agent: values read by analysers, with the
 * analyser's own timestamps (spec §11: replayed after an outage, in order).
 */
final class AgentResultsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'results' => ['required', 'array', 'min:1', 'max:500'],
            'results.*.barcode' => ['required', 'string', 'max:30'],
            'results.*.test_code' => ['required', 'string', 'max:20'],
            'results.*.parameter_code' => ['required', 'string', 'max:30'],
            'results.*.value' => ['required', 'string', 'max:255'],
            'results.*.instrument' => ['nullable', 'string', 'max:100'],
            'results.*.measured_at' => ['required', 'date'],
            'results.*.run_no' => ['nullable', 'integer', 'min:1'],
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

    /** @return list<AnalyserResult> */
    public function results(): array
    {
        return array_map(fn (array $result) => new AnalyserResult(
            $result['barcode'],
            $result['test_code'],
            $result['parameter_code'],
            $result['value'],
            $result['instrument'] ?? null,
            CarbonImmutable::parse($result['measured_at'])->utc(),
            isset($result['run_no']) ? (int) $result['run_no'] : null,
        ), array_values($this->validated('results')));
    }
}
