<?php

namespace App\Modules\Ledger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A wallet top-up. Franchise users top up their own wallet; HQ names the franchise. */
final class WalletTopupRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'franchise_id' => ['nullable', 'uuid'],
            'amount' => ['required', 'decimal:0,2', 'min:1', 'max:9999999999'],
        ];
    }
}
