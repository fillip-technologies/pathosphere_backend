<?php

namespace App\Modules\Catalogue\Http\Resources;

use App\Modules\Catalogue\Models\ReferenceRange;
use App\Modules\Catalogue\Models\TestParameter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TestParameter */
final class TestParameterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_id' => $this->test_id,
            'code' => $this->code,
            'parameter_name' => $this->parameter_name,
            'unit' => $this->unit,
            'result_type' => $this->result_type,
            'decimal_places' => $this->decimal_places,
            'options' => $this->options,
            'formula' => $this->formula,
            'display_order' => $this->display_order,
            'reference_ranges' => $this->whenLoaded('referenceRanges', fn () => $this->referenceRanges->map(fn (ReferenceRange $range) => [
                'gender' => $range->gender,
                'age_min_days' => $range->age_min_days,
                'age_max_days' => $range->age_max_days,
                'ref_low' => $range->ref_low,
                'ref_high' => $range->ref_high,
                'critical_low' => $range->critical_low,
                'critical_high' => $range->critical_high,
                'display_text' => $range->display_text,
            ])->values()->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
