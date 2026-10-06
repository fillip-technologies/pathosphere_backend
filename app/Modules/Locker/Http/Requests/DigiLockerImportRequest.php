<?php

namespace App\Modules\Locker\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** One DigiLocker document to keep; category and title default from DigiLocker. */
final class DigiLockerImportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'uri' => ['required', 'string', 'max:150'],
            'category' => ['sometimes', 'string', 'exists:record_categories,code'],
            'title' => ['sometimes', 'string', 'max:200'],
        ];
    }
}
