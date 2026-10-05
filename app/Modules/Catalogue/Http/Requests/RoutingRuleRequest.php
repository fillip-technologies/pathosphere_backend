<?php

namespace App\Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Create (POST) and partial update (PATCH). The source branch is fixed at creation. */
final class RoutingRuleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        $rules = [
            'test_id' => ['sometimes', 'nullable', 'uuid'],
            'processing_branch_id' => [$creating ? 'required' : 'sometimes', 'uuid'],
            'priority' => ['sometimes', 'integer', 'between:1,1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($creating) {
            $rules['source_branch_id'] = ['required', 'uuid'];
        }

        return $rules;
    }
}
