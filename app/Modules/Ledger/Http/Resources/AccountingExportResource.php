<?php

namespace App\Modules\Ledger\Http\Resources;

use App\Modules\Ledger\Enums\AccountingExportStatus;
use App\Modules\Ledger\Models\AccountingExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccountingExport */
final class AccountingExportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'format' => $this->format,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'status' => $this->status,
            'voucher_count' => $this->voucher_count,
            'total_amount' => $this->total_amount,
            'checksum' => $this->checksum,
            'size_bytes' => $this->size_bytes,
            'error_message' => $this->error_message,
            'file_url' => $this->status === AccountingExportStatus::Ready ? "/api/v1/accounting-exports/{$this->id}/file" : null,
            'requested_by' => $this->created_by,
            'generated_at' => $this->generated_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
