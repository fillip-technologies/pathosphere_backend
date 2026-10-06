<?php

namespace App\Modules\Lab\Http\Resources;

use App\Modules\Lab\Services\ReportDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A report with its departments: the tests in each, their progress, and
 * whether the department is signed.
 *
 * @property ReportDetail $resource
 */
final class ReportDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...ReportResource::make($this->resource->report)->toArray($request),
            'departments' => array_map(fn (array $section) => [
                'department_id' => $section['department']->id,
                'name' => $section['department']->name,
                'tests' => $section['tests'],
                'signed_at' => $section['signature']?->signed_at->toIso8601ZuluString(),
                'signatory_id' => $section['signature']?->signatory_id,
            ], $this->resource->sections),
        ];
    }
}
