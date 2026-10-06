<?php

namespace App\Modules\Ledger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A manual ledger correction: one partner, one side, a reason from the lookup list (spec §6). */
final class LedgerAdjustmentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'franchise_id' => ['required_without:b2b_client_id', 'prohibits:b2b_client_id', 'nullable', 'uuid'],
            'b2b_client_id' => ['required_without:franchise_id', 'nullable', 'uuid'],
            'side' => ['required', 'in:debit,credit'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'reason' => ['required', Rule::in(array_keys((array) config('pathology.ledger.adjustment_reasons')))],
            'note' => ['nullable', 'string', 'max:150'],
        ];
    }
}
