<?php

namespace App\Modules\Lab\Http\Resources;

use App\Modules\Lab\Models\InterfaceAgent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InterfaceAgent */
final class InterfaceAgentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'key_prefix' => $this->key_prefix,
            'allowed_ips' => $this->allowed_ips,
            'is_active' => $this->is_active,
            'last_seen_at' => $this->last_seen_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at,
        ];
    }
}
