<?php

namespace App\Modules\Samples\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CancelStockTransferRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
