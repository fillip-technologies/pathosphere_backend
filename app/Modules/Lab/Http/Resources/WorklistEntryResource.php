<?php

namespace App\Modules\Lab\Http\Resources;

use App\Modules\Catalogue\Services\ParameterDefinition;
use App\Modules\Lab\Services\WorklistDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A test on the lab's worklist with every parameter and its current result.
 * `etag` is what POST /results expects in If-Match when results change.
 *
 * @property WorklistDetail $resource
 */
final class WorklistEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = $this->resource;
        $entry = $detail->entry;

        return [
            'id' => $entry->id,
            'order_item_id' => $entry->order_item_id,
            'status' => $entry->status,
            'current_run' => $entry->current_run,
            'due_at' => $entry->due_at?->toIso8601ZuluString(),
            'processing_branch_id' => $entry->processing_branch_id,
            'department_id' => $entry->department_id,
            'order' => ['id' => $detail->order->orderId, 'order_no' => $detail->order->orderNo],
            'patient' => [
                'id' => $detail->order->patientId,
                'name' => $detail->order->patientName,
                'uhid' => $detail->order->uhid,
                'age_years' => $detail->order->ageYears,
                'gender' => $detail->order->gender,
            ],
            'sample' => ['id' => $entry->sample_id, 'barcode' => $detail->barcode, 'sample_type' => $detail->sampleType],
            'test' => ['id' => $detail->test->testId, 'code' => $detail->test->code, 'name' => $detail->test->name],
            'parameters' => array_map(fn (ParameterDefinition $parameter) => [
                'id' => $parameter->id,
                'code' => $parameter->code,
                'name' => $parameter->name,
                'unit' => $parameter->unit,
                'result_type' => $parameter->resultType,
                'options' => $parameter->options,
                'is_calculated' => $parameter->isCalculated(),
                'result' => isset($detail->resultsByParameter[$parameter->id])
                    ? LabResultResource::make($detail->resultsByParameter[$parameter->id])->toArray($request)
                    : null,
            ], $detail->test->parameters),
            'etag' => $detail->etag,
            'updated_at' => $entry->updated_at,
        ];
    }
}
