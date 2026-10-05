<?php

namespace App\Modules\Samples\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Send a sample to another lab; without a lab, the routing rules choose. */
final class RerouteSampleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'processing_branch_id' => ['nullable', 'uuid'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
