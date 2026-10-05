<?php

namespace App\Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RoutingResolutionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid'],
            'test_ids' => ['required', 'array', 'min:1', 'max:100'],
            'test_ids.*' => ['uuid', 'distinct'],
        ];
    }
}
