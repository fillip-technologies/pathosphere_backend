<?php

namespace App\Modules\Ledger\Http\Resources;

use App\Modules\Ledger\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Settlement */
final class SettlementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'settlement_no' => $this->settlement_no,
            'franchise_id' => $this->franchise_id,
            'b2b_client_id' => $this->b2b_client_id,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'gross_billing' => $this->gross_billing,
            'partner_share' => $this->partner_share,
            'hq_share' => $this->hq_share,
            'tax' => $this->tax,
            'net_amount' => $this->net_amount,
            'closing_balance' => $this->closing_balance,
            'direction' => $this->direction,
            'status' => $this->status,
            'dispute_note' => $this->dispute_note,
            'approved_by' => $this->approved_by,
            'settled_at' => $this->settled_at,
            'payment_reference' => $this->payment_reference,
            'statement_url' => "/api/v1/settlements/{$this->id}/statement",
            'entries' => LedgerEntryResource::collection($this->whenLoaded('entries')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
