<?php

namespace App\Modules\Network\Http\Requests;

use App\Modules\Network\Enums\BranchOwnerType;
use App\Modules\Network\Enums\BranchType;
use App\Modules\Shared\Http\Validation\Formats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and partial update (PATCH) of a branch. Code and ownership
 * are set once at creation.
 */
final class BranchRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'region_id' => [$required, 'uuid'],
            'name' => [$required, 'string', 'max:150'],
            'branch_type' => [$required, Rule::enum(BranchType::class)],
            'mrp_price_list_id' => ['sometimes', 'nullable', 'uuid'],
            'nabl_certificate_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'nabl_valid_till' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hfr_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'clinical_establishment_reg_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => [$required, 'string', 'max:1000'],
            'pincode' => [$required, Formats::PINCODE],
            'latitude' => ['sometimes', 'nullable', 'decimal:0,6', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'decimal:0,6', 'between:-180,180'],
            'phone' => [$required, Formats::PHONE],
            'working_hours' => ['sometimes', 'nullable', 'array'],
            'opened_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];

        if ($creating) {
            $rules += [
                'branch_code' => ['required', 'string', 'max:20', Formats::CODE, Rule::unique('branches', 'branch_code')],
                'owner_type' => ['required', Rule::enum(BranchOwnerType::class)],
                'franchise_id' => ['nullable', 'uuid'],
            ];
        }

        return $rules;
    }
}
