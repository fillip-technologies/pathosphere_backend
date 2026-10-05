<?php

namespace App\Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The complete set of prices for a list (PUT replaces all items). */
final class PriceListItemsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:5000'],
            'items.*.test_id' => ['nullable', 'uuid', 'required_without:items.*.package_id', 'prohibits:items.*.package_id', 'distinct'],
            'items.*.package_id' => ['nullable', 'uuid', 'required_without:items.*.test_id', 'distinct'],
            'items.*.price' => ['required', 'decimal:0,2', 'min:0'],
        ];
    }

    /** @return list<array{test_id?: string|null, package_id?: string|null, price: string}> */
    public function items(): array
    {
        return array_values($this->validated('items'));
    }
}
