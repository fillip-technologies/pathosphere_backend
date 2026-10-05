<?php

namespace App\Modules\Samples\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A reason from the configured list, plus a free-text note (spec §6 reasons rule). */
final class RejectSampleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(array_keys(config('pathology.samples.rejection_reasons')))],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
