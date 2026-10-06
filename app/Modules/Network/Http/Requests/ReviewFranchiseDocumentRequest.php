<?php

namespace App\Modules\Network\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReviewFranchiseDocumentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:verified,rejected'],
            'rejection_note' => ['required_if:decision,rejected', 'nullable', 'string', 'max:1000'],
        ];
    }
}
