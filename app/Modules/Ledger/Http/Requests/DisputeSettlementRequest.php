<?php

namespace App\Modules\Ledger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DisputeSettlementRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'max:1000']];
    }
}
