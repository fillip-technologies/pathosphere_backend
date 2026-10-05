<?php

namespace App\Modules\Network\Http\Requests;

use App\Modules\Network\Enums\RegionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST) and partial update (PATCH) of a region. */
final class RegionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'region_type' => [$required, Rule::enum(RegionType::class)],
            'parent_region_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
