<?php

namespace App\Modules\Samples\Http\Resources;

use App\Modules\Samples\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockTransfer */
final class StockTransferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_no' => $this->transfer_no,
            'from_branch_id' => $this->from_branch_id,
            'to_branch_id' => $this->to_branch_id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'batch_no' => $this->batch_no,
            'quantity' => $this->quantity,
            'charge_amount' => $this->charge_amount,
            'status' => $this->status,
            'dispatched_at' => $this->dispatched_at,
            'received_at' => $this->received_at,
            'cancelled_reason' => $this->cancelled_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
