<?php

namespace App\Modules\Booking\Http\Resources;

use App\Modules\Booking\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
final class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'amount' => $this->amount,
            'mode' => $this->mode,
            'gateway' => $this->gateway,
            'transaction_id' => $this->transaction_id,
            'received_by' => $this->received_by,
            'paid_at' => $this->paid_at->toIso8601ZuluString(),
            'status' => $this->status,
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
        ];
    }
}
