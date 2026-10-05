<?php

namespace App\Modules\Samples\Http\Resources;

use App\Modules\Catalogue\Services\SampleRequirement;
use App\Modules\Samples\Models\ManifestItem;
use App\Modules\Samples\Services\SampleDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sample with its patient, tests, label and trace: every manifest leg it
 * travelled, oldest first.
 *
 * @property SampleDetail $resource
 */
final class SampleDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = $this->resource;

        return [
            ...SampleResource::make($detail->sample)->toArray($request),
            'order' => ['id' => $detail->order->orderId, 'order_no' => $detail->order->orderNo],
            'patient' => [
                'id' => $detail->order->patientId,
                'name' => $detail->order->patientName,
                'uhid' => $detail->order->uhid,
                'age_years' => $detail->order->ageYears,
                'gender' => $detail->order->gender,
            ],
            'tests' => array_map(fn (string $orderItemId, SampleRequirement $test) => [
                'order_item_id' => $orderItemId,
                'test_id' => $test->testId,
                'code' => $test->code,
                'name' => $test->name,
            ], array_keys($detail->testsByOrderItem), $detail->testsByOrderItem),
            'journey' => array_map(fn (ManifestItem $leg) => [
                'manifest_id' => $leg->manifest->id,
                'manifest_no' => $leg->manifest->manifest_no,
                'from_branch_id' => $leg->manifest->from_branch_id,
                'to_branch_id' => $leg->manifest->to_branch_id,
                'manifest_status' => $leg->manifest->status,
                'dispatched_at' => $leg->manifest->dispatched_at?->toIso8601ZuluString(),
                'condition' => $leg->condition,
                'rejection_reason' => $leg->rejection_reason,
                'scanned_at' => $leg->scanned_at?->toIso8601ZuluString(),
            ], $detail->journey),
            'redraw_sample_id' => $detail->redrawSampleId,
            'label' => ['format' => 'zpl', 'content' => $detail->labelZpl],
        ];
    }
}
