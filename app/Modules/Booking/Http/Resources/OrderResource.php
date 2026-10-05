<?php

namespace App\Modules\Booking\Http\Resources;

use App\Modules\Booking\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
final class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'patient_id' => $this->patient_id,
            'branch_id' => $this->branch_id,
            'franchise_id' => $this->franchise_id,
            'b2b_client_id' => $this->b2b_client_id,
            'doctor_id' => $this->doctor_id,
            'order_source' => $this->order_source,
            'external_ref' => $this->external_ref,
            'order_date' => $this->order_date->toIso8601ZuluString(),
            'status' => $this->status,
            'cancelled_reason' => $this->cancelled_reason,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'invoices' => InvoiceResource::collection($this->whenLoaded('invoices')),
            'home_collection' => HomeCollectionResource::make($this->whenLoaded('homeCollection')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
