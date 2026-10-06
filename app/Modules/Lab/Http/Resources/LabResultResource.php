<?php

namespace App\Modules\Lab\Http\Resources;

use App\Modules\Lab\Models\LabResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LabResult */
final class LabResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_no' => $this->run_no,
            'value' => $this->value,
            'value_numeric' => $this->value_numeric,
            'unit' => $this->unit,
            'ref_range_text' => $this->ref_range_text,
            'flag' => $this->flag,
            'is_critical' => $this->is_critical,
            'source' => $this->source,
            'instrument' => $this->instrument,
            'comment' => $this->comment,
            'entered_by' => $this->entered_by,
            'entered_at' => $this->entered_at->toIso8601ZuluString(),
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at?->toIso8601ZuluString(),
            'is_final' => $this->is_final,
        ];
    }
}
