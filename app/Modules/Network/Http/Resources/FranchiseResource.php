<?php

namespace App\Modules\Network\Http\Resources;

use App\Modules\Network\Models\Franchise;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A franchise. The bank account number is shown masked only (spec §10:
 * encrypted at rest, never echoed back in full).
 *
 * @mixin Franchise
 */
final class FranchiseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'franchise_code' => $this->franchise_code,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'owner_name' => $this->owner_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'gstin' => $this->gstin,
            'pan' => $this->pan,
            'address' => $this->address,
            'bank_account_no_masked' => $this->bank_account_no === null ? null : '••••'.substr($this->bank_account_no, -4),
            'bank_ifsc' => $this->bank_ifsc,
            'region_id' => $this->region_id,
            'partner_price_list_id' => $this->partner_price_list_id,
            'credit_limit' => $this->credit_limit,
            'current_balance' => $this->current_balance,
            'status' => $this->status,
            'onboarded_at' => $this->onboarded_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
