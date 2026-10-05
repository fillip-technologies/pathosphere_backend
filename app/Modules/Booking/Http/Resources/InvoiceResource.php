<?php

namespace App\Modules\Booking\Http\Resources;

use App\Modules\Booking\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
final class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'order_id' => $this->order_id,
            'billed_by_branch_id' => $this->billed_by_branch_id,
            'bill_to_type' => $this->bill_to_type,
            'b2b_client_id' => $this->b2b_client_id,
            'invoice_date' => $this->invoice_date->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'amount' => $this->amount,
            'discount' => $this->discount,
            'tax' => $this->tax,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'balance_due' => $this->balanceDue(),
            'payment_status' => $this->payment_status,
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at,
        ];
    }
}
