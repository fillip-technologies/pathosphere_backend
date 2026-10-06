<?php

namespace App\Modules\Ledger\Http\Resources;

use App\Modules\Ledger\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LedgerEntry */
final class LedgerEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'franchise_id' => $this->franchise_id,
            'b2b_client_id' => $this->b2b_client_id,
            'entry_type' => $this->entry_type,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'balance_after' => $this->balance_after,
            'narration' => $this->narration,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
        ];
    }
}
