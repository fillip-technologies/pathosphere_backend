<?php

namespace App\Modules\Locker\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A new version of an uploaded record's file (multipart). */
final class RecordFileRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['file' => self::fileRules()];
    }

    /** @return list<string> PDF or a photo, up to the configured size */
    public static function fileRules(): array
    {
        return [
            'required',
            'file',
            'mimes:'.implode(',', (array) config('pathology.locker.upload_mimes')),
            'max:'.(int) config('pathology.locker.upload_max_kb'),
        ];
    }
}
