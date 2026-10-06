<?php

namespace App\Modules\Network\Http\Requests;

use App\Modules\Network\Enums\B2bClientType;
use App\Modules\Shared\Http\Validation\Formats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST) and partial update (PATCH) of a B2B client. The code is set once. */
final class B2bClientRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'region_id' => [$required, 'uuid'],
            'serviced_by_branch_id' => [$required, 'uuid'],
            'client_type' => [$required, Rule::enum(B2bClientType::class)],
            'name' => [$required, 'string', 'max:200'],
            'gstin' => ['sometimes', 'nullable', Formats::GSTIN],
            'contact_name' => [$required, 'string', 'max:150'],
            'phone' => [$required, Formats::PHONE],
            'email' => [$required, 'email', 'max:150'],
            'billing_address' => [$required, 'string', 'max:1000'],
            'price_list_id' => [$required, 'uuid'],
            'credit_limit' => ['sometimes', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'credit_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'withhold_reports_when_overdue' => ['sometimes', 'boolean'],
        ];

        if ($creating) {
            $rules['client_code'] = ['required', 'string', 'max:20', Formats::CODE, Rule::unique('b2b_clients', 'client_code')];
        }

        return $rules;
    }
}
