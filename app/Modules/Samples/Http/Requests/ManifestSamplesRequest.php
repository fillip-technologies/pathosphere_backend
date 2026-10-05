<?php

namespace App\Modules\Samples\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Barcodes scanned into an open bag. */
final class ManifestSamplesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'barcodes' => ['required', 'array', 'min:1', 'max:500'],
            'barcodes.*' => ['required', 'string', 'max:30', 'distinct'],
        ];
    }

    /** @return list<string> */
    public function barcodes(): array
    {
        return array_values($this->validated('barcodes'));
    }
}
