<?php

namespace App\Modules\Network\Http\Resources;

use App\Modules\Network\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branch */
final class BranchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_code' => $this->branch_code,
            'name' => $this->name,
            'branch_type' => $this->branch_type,
            'mrp_price_list_id' => $this->mrp_price_list_id,
            'owner_type' => $this->owner_type,
            'franchise_id' => $this->franchise_id,
            'region_id' => $this->region_id,
            'status' => $this->status,
            'nabl_certificate_no' => $this->nabl_certificate_no,
            'nabl_valid_till' => $this->nabl_valid_till?->toDateString(),
            'hfr_id' => $this->hfr_id,
            'clinical_establishment_reg_no' => $this->clinical_establishment_reg_no,
            'address' => $this->address,
            'pincode' => $this->pincode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'phone' => $this->phone,
            'working_hours' => $this->working_hours,
            'opened_at' => $this->opened_at?->toDateString(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
