<?php

namespace App\Modules\Network\Http\Resources;

use App\Modules\Network\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Organization */
final class OrganizationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'gstin' => $this->gstin,
            'pan' => $this->pan,
            'cin' => $this->cin,
            'hq_address' => $this->hq_address,
            'logo_path' => $this->logo_path,
            'settings' => (object) $this->settings,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
