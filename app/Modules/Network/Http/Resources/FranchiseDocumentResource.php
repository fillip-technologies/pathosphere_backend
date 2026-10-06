<?php

namespace App\Modules\Network\Http\Resources;

use App\Modules\Network\Models\FranchiseDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A KYC paper. The file itself is fetched from its own endpoint, never as a
 * storage path.
 *
 * @mixin FranchiseDocument
 */
final class FranchiseDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'franchise_id' => $this->franchise_id,
            'doc_type' => $this->doc_type,
            'status' => $this->status,
            'vendor_reference' => $this->vendor_reference,
            'rejection_note' => $this->rejection_note,
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at,
            'expires_on' => $this->expires_on?->toDateString(),
            'file_url' => "/api/v1/franchise-documents/{$this->id}/file",
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
