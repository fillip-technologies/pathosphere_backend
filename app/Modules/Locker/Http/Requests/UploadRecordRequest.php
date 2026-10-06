<?php

namespace App\Modules\Locker\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A record the patient adds (multipart): what it is, when it is from, and the file. */
final class UploadRecordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'exists:record_categories,code'],
            'title' => ['required', 'string', 'max:200'],
            'record_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'provider_facility' => ['nullable', 'string', 'max:200'],
            'file' => RecordFileRequest::fileRules(),
        ];
    }
}
