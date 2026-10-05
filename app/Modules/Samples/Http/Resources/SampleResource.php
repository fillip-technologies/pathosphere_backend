<?php

namespace App\Modules\Samples\Http\Resources;

use App\Modules\Samples\Domain\StabilityChecker;
use App\Modules\Samples\Enums\SampleStatus;
use App\Modules\Samples\Models\Sample;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sample without patient details, for lists and manifests.
 *
 * @mixin Sample
 */
final class SampleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'barcode' => $this->barcode,
            'order_id' => $this->order_id,
            'sample_type' => $this->sample_type,
            'container_type' => $this->container_type,
            'status' => $this->status,
            'collected_branch_id' => $this->collected_branch_id,
            'processing_branch_id' => $this->processing_branch_id,
            'current_branch_id' => $this->current_branch_id,
            'collected_by' => $this->collected_by,
            'collection_datetime' => $this->collection_datetime?->toIso8601ZuluString(),
            'stable_until' => $this->stable_until?->toIso8601ZuluString(),
            'stability_exceeded' => $this->status === SampleStatus::InTransit && StabilityChecker::isExceeded($this->stable_until, CarbonImmutable::now()),
            'received_at' => $this->received_at?->toIso8601ZuluString(),
            'received_by' => $this->received_by,
            'rejection_reason' => $this->rejection_reason,
            'rejection_note' => $this->rejection_note,
            'recollection_of_id' => $this->recollection_of_id,
            'updated_at' => $this->updated_at,
        ];
    }
}
