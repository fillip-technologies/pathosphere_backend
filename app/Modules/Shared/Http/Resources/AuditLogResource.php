<?php

namespace App\Modules\Shared\Http\Resources;

use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
final class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'user_id' => $this->user_id,
            'franchise_id' => $this->franchise_id,
            'branch_id' => $this->branch_id,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'ip_address' => $this->ip_address,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at,
        ];
    }
}
