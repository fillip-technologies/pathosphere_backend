<?php

namespace App\Modules\Network\Http\Requests;

use App\Modules\Network\Enums\FranchiseDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A KYC paper (multipart): PDF or a photo, at most 5 MB. */
final class UploadFranchiseDocumentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'doc_type' => ['required', Rule::enum(FranchiseDocumentType::class)],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after:today'],
        ];
    }
}
