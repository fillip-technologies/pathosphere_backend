<?php

namespace App\Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PriceListImportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']];
    }
}
