<?php

namespace App\Modules\Booking\Http\Requests;

use App\Modules\Shared\Money\Money;
use Illuminate\Foundation\Http\FormRequest;

final class RefundRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'reason' => ['required', 'string', 'max:100'],
        ];
    }

    public function amount(): Money
    {
        return Money::fromString((string) $this->validated('amount'));
    }
}
