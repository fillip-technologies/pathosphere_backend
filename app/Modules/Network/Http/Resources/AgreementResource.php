<?php

namespace App\Modules\Network\Http\Resources;

use App\Modules\Network\Models\FranchiseAgreement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FranchiseAgreement */
final class AgreementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'franchise_id' => $this->franchise_id,
            'agreement_no' => $this->agreement_no,
            'franchise_model' => $this->franchise_model,
            'billing_model' => $this->billing_model,
            'commission_pct' => $this->commission_pct,
            'franchise_fee' => $this->franchise_fee,
            'security_deposit' => $this->security_deposit,
            'min_monthly_business' => $this->min_monthly_business,
            'territory' => $this->territory,
            'territory_pincodes' => $this->pincodeList(),
            'settlement_cycle' => $this->settlement_cycle,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'status' => $this->status,
            'esign_reference' => $this->esign_reference,
            'signed_at' => $this->signed_at,
            'signed_document_url' => $this->signed_doc_path === null ? null : "/api/v1/agreements/{$this->id}/document",
            'approved_by' => $this->approved_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
