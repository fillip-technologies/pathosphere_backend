<?php

namespace App\Modules\Lab\Http\Resources;

use App\Modules\Lab\Models\Report;
use App\Modules\Lab\Models\ReportSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Report */
final class ReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'patient_id' => $this->patient_id,
            'branch_id' => $this->branch_id,
            'b2b_client_id' => $this->b2b_client_id,
            'processing_branch_id' => $this->processing_branch_id,
            'version' => $this->version,
            'status' => $this->status,
            'is_partial' => $this->is_partial,
            'amendment_reason' => $this->amendment_reason,
            'released_at' => $this->released_at?->toIso8601ZuluString(),
            'released_by' => $this->released_by,
            'pdf_ready' => $this->pdf_path !== null,
            'pdf_sha256' => $this->pdf_sha256,
            'signatures' => $this->signatures->map(fn (ReportSignature $signature) => [
                'department_id' => $signature->department_id,
                'signatory_id' => $signature->signatory_id,
                'signed_at' => $signature->signed_at->toIso8601ZuluString(),
            ])->values()->all(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
