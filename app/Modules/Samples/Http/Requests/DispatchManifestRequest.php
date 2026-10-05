<?php

namespace App\Modules\Samples\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Courier and temperature check at dispatch (spec §7.6). */
final class DispatchManifestRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'courier_name' => ['nullable', 'string', 'max:100'],
            'temperature_ok' => ['nullable', 'boolean'],
            'dispatch_temp_c' => ['nullable', 'decimal:0,1', 'between:-99.9,99.9'],
        ];
    }
}
