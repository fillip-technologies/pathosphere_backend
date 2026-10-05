<?php

namespace App\Modules\Catalogue\Http\Resources;

use App\Modules\Catalogue\Models\PriceListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PriceListItem */
final class PriceListItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->test ?? $this->package;

        return [
            'id' => $this->id,
            'item_type' => $this->test_id !== null ? 'test' : 'package',
            'test_id' => $this->test_id,
            'package_id' => $this->package_id,
            'code' => $item?->code,
            'name' => $item?->name,
            'price' => $this->price,
        ];
    }
}
