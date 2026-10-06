<?php

namespace App\Modules\Network\Http\Resources;

use App\Modules\Network\Models\B2bClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin B2bClient */
final class B2bClientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_code' => $this->client_code,
            'name' => $this->name,
            'client_type' => $this->client_type,
            'gstin' => $this->gstin,
            'contact_name' => $this->contact_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'billing_address' => $this->billing_address,
            'region_id' => $this->region_id,
            'serviced_by_branch_id' => $this->serviced_by_branch_id,
            'price_list_id' => $this->price_list_id,
            'credit_limit' => $this->credit_limit,
            'credit_days' => $this->credit_days,
            'current_balance' => $this->current_balance,
            'withhold_reports_when_overdue' => $this->withhold_reports_when_overdue,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
