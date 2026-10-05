<?php

namespace App\Modules\Catalogue\Http\Resources;

use App\Modules\Catalogue\Models\LabTest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LabTest */
final class LabTestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'department_id' => $this->department_id,
            'loinc_code' => $this->loinc_code,
            'sample_type' => $this->sample_type,
            'container_type' => $this->container_type,
            'sample_volume_ml' => $this->sample_volume_ml,
            'storage_temp' => $this->storage_temp,
            'stability_hours' => $this->stability_hours,
            'tat_hours' => $this->tat_hours,
            'method' => $this->method,
            'patient_instructions' => $this->patient_instructions,
            'base_price' => $this->base_price,
            'is_outsourced_only' => $this->is_outsourced_only,
            'is_active' => $this->is_active,
            'parameters' => TestParameterResource::collection($this->whenLoaded('parameters')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
