<?php

namespace App\Modules\Ledger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The bank transfer that settled the statement (UTR); not needed when nothing is payable. */
final class MarkSettledRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['payment_reference' => ['nullable', 'string', 'max:100']];
    }
}
