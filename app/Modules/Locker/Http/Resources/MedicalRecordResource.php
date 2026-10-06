<?php

namespace App\Modules\Locker\Http\Resources;

use App\Modules\Locker\Models\MedicalRecord;
use App\Modules\Locker\Models\PathologyResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A record in the timeline. Lab reports add the lab and how many results
 * were outside their range.
 *
 * @mixin MedicalRecord
 */
final class MedicalRecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $pathologyReport = $this->pathologyReport;

        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'category' => [
                'code' => $this->category->code,
                'name' => $this->category->name,
                'icon' => $this->category->icon,
            ],
            'source' => $this->source,
            'record_date' => $this->record_date->toDateString(),
            'title' => $this->title,
            'provider_facility' => $this->provider_facility,
            'is_current' => $this->superseded_by_id === null,
            'superseded_by_id' => $this->superseded_by_id,
            'lab_name' => $pathologyReport?->lab_name,
            'abnormal_count' => $pathologyReport === null
                ? null
                : $pathologyReport->results->filter(fn (PathologyResult $result) => $result->flag !== null && $result->flag !== 'normal')->count(),
            'file_versions' => $pathologyReport !== null ? 1 : $this->documents->count(),
            'created_at' => $this->created_at,
        ];
    }
}
