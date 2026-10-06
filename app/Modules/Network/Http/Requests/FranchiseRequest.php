<?php

namespace App\Modules\Network\Http\Requests;

use App\Modules\Shared\Http\Validation\Formats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and partial update (PATCH) of a franchise. The code is set
 * once; status and balance never change here.
 */
final class FranchiseRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'region_id' => [$required, 'uuid'],
            'name' => [$required, 'string', 'max:150'],
            'legal_name' => [$required, 'string', 'max:200'],
            'owner_name' => [$required, 'string', 'max:150'],
            'phone' => [$required, Formats::PHONE],
            'email' => [$required, 'email', 'max:150'],
            'gstin' => ['sometimes', 'nullable', Formats::GSTIN],
            'pan' => [$required, Formats::PAN],
            'address' => [$required, 'string', 'max:1000'],
            'bank_account_no' => ['sometimes', 'nullable', 'regex:/^\d{9,20}$/'],
            'bank_ifsc' => ['sometimes', 'nullable', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'partner_price_list_id' => ['sometimes', 'nullable', 'uuid'],
            'credit_limit' => ['sometimes', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ];

        if ($creating) {
            $rules['franchise_code'] = ['required', 'string', 'max:20', Formats::CODE, Rule::unique('franchises', 'franchise_code')];
        }

        return $rules;
    }
}
