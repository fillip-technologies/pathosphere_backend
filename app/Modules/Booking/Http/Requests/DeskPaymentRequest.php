<?php

namespace App\Modules\Booking\Http\Requests;

use App\Modules\Booking\Enums\PaymentMode;
use App\Modules\Booking\Services\DeskPayment;
use App\Modules\Shared\Money\Money;
use Illuminate\Foundation\Http\FormRequest;

final class DeskPaymentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:cash,card,upi'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'transaction_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function payment(): DeskPayment
    {
        return new DeskPayment(
            PaymentMode::from($this->validated('mode')),
            Money::fromString((string) $this->validated('amount')),
            $this->validated('transaction_id'),
        );
    }
}
