<?php

namespace App\Modules\Samples\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A pre-printed label stuck on the tube (Code 128, letters, digits and hyphens). */
final class AssignBarcodeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('barcode'))) {
            $this->merge(['barcode' => strtoupper(trim($this->input('barcode')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'barcode' => ['required', 'string', 'regex:/^[A-Z0-9-]{4,30}$/'],
        ];
    }
}
