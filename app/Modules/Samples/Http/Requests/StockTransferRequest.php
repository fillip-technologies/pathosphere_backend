<?php

namespace App\Modules\Samples\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Request (POST) a transfer, or change quantity / charge while it is still requested (PATCH). */
final class StockTransferRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isMethod('PATCH')) {
            return [
                'quantity' => ['sometimes', 'decimal:0,2', 'gt:0', 'max:9999999999'],
                'charge_amount' => ['sometimes', 'decimal:0,2', 'min:0', 'max:9999999999'],
            ];
        }

        return [
            'from_branch_id' => ['required', 'uuid'],
            'to_branch_id' => ['required', 'uuid', 'different:from_branch_id'],
            'item_code' => ['required', 'string', 'max:30'],
            'batch_no' => ['required', 'string', 'max:50'],
            'quantity' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'charge_amount' => ['sometimes', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ];
    }
}
