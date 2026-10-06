<?php

namespace App\Modules\Network\Http\Requests;

use App\Modules\Network\Enums\BillingModel;
use App\Modules\Network\Enums\FranchiseModel;
use App\Modules\Network\Enums\SettlementCycle;
use App\Modules\Shared\Http\Validation\Formats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST) and partial update (PATCH) of a draft agreement. */
final class AgreementRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'franchise_model' => [$required, Rule::enum(FranchiseModel::class)],
            'billing_model' => [$required, Rule::enum(BillingModel::class)],
            'commission_pct' => [
                Rule::requiredIf($creating && $this->input('billing_model') === BillingModel::RevenueShare->value),
                'nullable', 'decimal:0,2', 'min:0', 'max:100',
            ],
            'franchise_fee' => ['sometimes', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'security_deposit' => ['sometimes', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'min_monthly_business' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'territory' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'territory_pincodes' => ['sometimes', 'array', 'max:500'],
            'territory_pincodes.*' => ['distinct', Formats::PINCODE],
            'settlement_cycle' => [$required, Rule::enum(SettlementCycle::class)],
            'start_date' => [$required, 'date_format:Y-m-d'],
            'end_date' => [$required, 'date_format:Y-m-d', ...($this->has('start_date') ? ['after:start_date'] : [])],
        ];
    }

    /** @return array<string, mixed> */
    public function terms(): array
    {
        return $this->safe()->except('territory_pincodes');
    }

    /** @return list<string>|null null when the request leaves the territory alone */
    public function pincodes(): ?array
    {
        return $this->has('territory_pincodes') ? array_values($this->validated('territory_pincodes')) : null;
    }
}
