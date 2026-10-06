<?php

namespace App\Modules\Samples\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The sender may set the charge as it ships. */
final class DispatchStockRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['charge_amount' => ['sometimes', 'decimal:0,2', 'min:0', 'max:9999999999']];
    }
}
