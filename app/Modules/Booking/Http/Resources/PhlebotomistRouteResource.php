<?php

namespace App\Modules\Booking\Http\Resources;

use App\Modules\Booking\Domain\PlannedStop;
use App\Modules\Booking\Services\PhlebotomistRoute;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PhlebotomistRoute */
final class PhlebotomistRouteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $sequence = 0;

        return [
            'phlebotomist_id' => $this->phlebotomistId,
            'date' => $this->date->toDateString(),
            'start' => $this->start === null ? null : [
                'branch_id' => $this->start->branchId,
                'latitude' => $this->start->latitude,
                'longitude' => $this->start->longitude,
            ],
            'total_distance_m' => array_sum(array_map(fn (PlannedStop $stop) => $stop->legMetres, $this->stops)),
            'stops' => array_map(function (PlannedStop $stop) use (&$sequence): array {
                $visit = $this->visits[$stop->stop->visitId];

                return [
                    'sequence' => ++$sequence,
                    'home_collection_id' => $visit->id,
                    'order_id' => $visit->order_id,
                    'status' => $visit->status,
                    'address' => $visit->address,
                    'pincode' => $visit->pincode,
                    'latitude' => $visit->latitude,
                    'longitude' => $visit->longitude,
                    'slot_start' => $visit->slot_start->toIso8601ZuluString(),
                    'slot_end' => $visit->slot_end->toIso8601ZuluString(),
                    // Estimated road distance from the previous stop (or the branch); 0 when either place is unknown.
                    'leg_distance_m' => $stop->legMetres,
                    'estimated_start' => CarbonImmutable::createFromTimestampUTC($stop->visitStartsAt)->toIso8601ZuluString(),
                    'on_time' => $stop->onTime,
                ];
            }, $this->stops),
        ];
    }
}
