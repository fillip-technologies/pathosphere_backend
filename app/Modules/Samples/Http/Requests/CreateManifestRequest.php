<?php

namespace App\Modules\Samples\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A new bag; staff at one branch may leave out the sending branch. */
final class CreateManifestRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from_branch_id' => ['nullable', 'uuid'],
            'to_branch_id' => ['required', 'uuid'],
            'barcodes' => ['sometimes', 'array', 'max:500'],
            'barcodes.*' => ['required', 'string', 'max:30', 'distinct'],
        ];
    }

    /** @return list<string> */
    public function barcodes(): array
    {
        return array_values($this->validated('barcodes', []));
    }
}
