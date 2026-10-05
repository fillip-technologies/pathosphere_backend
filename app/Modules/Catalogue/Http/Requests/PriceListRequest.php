<?php

namespace App\Modules\Catalogue\Http\Requests;

use App\Modules\Catalogue\Enums\PriceListType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST) and partial update (PATCH). The list type is fixed at creation. */
final class PriceListRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'name' => [$required, 'string', 'max:100'],
            'is_default_mrp' => ['sometimes', 'boolean'],
            'valid_from' => [$required, 'date_format:Y-m-d'],
            'valid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($creating) {
            $rules['list_type'] = ['required', Rule::enum(PriceListType::class)];
        }

        return $rules;
    }
}
