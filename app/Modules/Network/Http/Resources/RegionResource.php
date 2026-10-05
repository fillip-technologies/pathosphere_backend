<?php

namespace App\Modules\Network\Http\Resources;

use App\Modules\Network\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Region */
final class RegionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_region_id' => $this->parent_region_id,
            'name' => $this->name,
            'region_type' => $this->region_type,
            'children' => RegionResource::collection($this->whenLoaded('children')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
