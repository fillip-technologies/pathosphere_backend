<?php

namespace App\Modules\Catalogue\Http\Resources;

use App\Modules\Catalogue\Models\RoutingRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RoutingRule */
final class RoutingRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_branch_id' => $this->source_branch_id,
            'test_id' => $this->test_id,
            'processing_branch_id' => $this->processing_branch_id,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
