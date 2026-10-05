<?php

namespace App\Modules\Booking\Http\Resources;

use App\Modules\Booking\Models\HomeCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin HomeCollection */
final class HomeCollectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'branch_id' => $this->branch_id,
            'phlebotomist_id' => $this->phlebotomist_id,
            'address' => $this->address,
            'pincode' => $this->pincode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'slot_start' => $this->slot_start->toIso8601ZuluString(),
            'slot_end' => $this->slot_end->toIso8601ZuluString(),
            'collection_charge' => $this->collection_charge,
            'status' => $this->status,
            'status_note' => $this->status_note,
            'collected_at' => $this->collected_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at,
        ];
    }
}
