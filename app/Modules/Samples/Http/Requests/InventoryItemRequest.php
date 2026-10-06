<?php

namespace App\Modules\Samples\Http\Requests;

use App\Modules\Samples\Enums\InventoryCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST) and partial update (PATCH) of a stock batch. Branch, code and batch are set once. */
final class InventoryItemRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'name' => [$required, 'string', 'max:150'],
            'category' => [$required, Rule::enum(InventoryCategory::class)],
            'unit' => [$required, 'string', 'max:20'],
            'quantity' => [$required, 'decimal:0,2', 'min:0', 'max:9999999999'],
            'reorder_level' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'expiry_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];

        if ($creating) {
            $rules += [
                'branch_id' => ['required', 'uuid'],
                'item_code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9_.-]*$/'],
                'batch_no' => ['required', 'string', 'max:50'],
            ];
        }

        return $rules;
    }
}
