<?php

namespace App\Modules\Booking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PaymentLinkRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['invoice_id' => ['required', 'uuid']];
    }
}
