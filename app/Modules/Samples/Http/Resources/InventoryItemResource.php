<?php

namespace App\Modules\Samples\Http\Resources;

use App\Modules\Samples\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryItem */
final class InventoryItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'item_code' => $this->item_code,
            'name' => $this->name,
            'category' => $this->category,
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'reorder_level' => $this->reorder_level,
            'below_reorder_level' => $this->reorder_level !== null && bccomp($this->quantity, $this->reorder_level, 2) < 0,
            'batch_no' => $this->batch_no,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
