<?php

namespace App\Modules\Booking\Http\Resources;

use App\Modules\Booking\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderItem */
final class OrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_id' => $this->test_id,
            'package_id' => $this->package_id,
            'parent_item_id' => $this->parent_item_id,
            'processing_branch_id' => $this->processing_branch_id,
            'mrp_price' => $this->mrp_price,
            'partner_price' => $this->partner_price,
            'discount' => $this->discount,
            'net_price' => $this->net_price,
            'due_at' => $this->due_at?->toIso8601ZuluString(),
            'status' => $this->status,
        ];
    }
}
