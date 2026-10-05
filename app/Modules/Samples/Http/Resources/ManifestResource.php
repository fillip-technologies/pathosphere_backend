<?php

namespace App\Modules\Samples\Http\Resources;

use App\Modules\Samples\Domain\StabilityChecker;
use App\Modules\Samples\Models\Manifest;
use App\Modules\Samples\Models\ManifestItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Manifest */
final class ManifestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => $this->id,
            'manifest_no' => $this->manifest_no,
            'from_branch_id' => $this->from_branch_id,
            'to_branch_id' => $this->to_branch_id,
            'status' => $this->status,
            'courier_name' => $this->courier_name,
            'temperature_ok' => $this->temperature_ok,
            'dispatch_temp_c' => $this->dispatch_temp_c,
            'receipt_temp_c' => $this->receipt_temp_c,
            'dispatched_by' => $this->dispatched_by,
            'dispatched_at' => $this->dispatched_at?->toIso8601ZuluString(),
            'received_by' => $this->received_by,
            'received_at' => $this->received_at?->toIso8601ZuluString(),
            'missing_flagged_at' => $this->missing_flagged_at?->toIso8601ZuluString(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (ManifestItem $item) => [
                'sample_id' => $item->sample_id,
                'barcode' => $item->sample->barcode,
                'container_type' => $item->sample->container_type,
                'sample_status' => $item->sample->status,
                'stable_until' => $item->sample->stable_until?->toIso8601ZuluString(),
                'stability_exceeded' => StabilityChecker::isExceeded($item->sample->stable_until, $item->scanned_at ?? $now),
                'condition' => $item->condition,
                'rejection_reason' => $item->rejection_reason,
                'scanned_at' => $item->scanned_at?->toIso8601ZuluString(),
            ])->values()->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
