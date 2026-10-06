<?php

namespace App\Modules\Booking\Http\Resources;

use App\Modules\Booking\Domain\PlannedAssignment;
use App\Modules\Booking\Services\AutoAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AutoAssignment */
final class AutoAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'branch_id' => $this->branchId,
            'date' => $this->date->toDateString(),
            'assigned' => array_map(fn (PlannedAssignment $assignment) => [
                'home_collection_id' => $assignment->visitId,
                'phlebotomist_id' => $assignment->phlebotomistId,
                // Estimated road distance this visit adds to the phlebotomist's day.
                'added_distance_m' => $assignment->addedMetres,
            ], $this->plan->assigned),
            'unassigned' => array_map(
                fn (string $visitId, string $reason) => ['home_collection_id' => $visitId, 'reason' => $reason],
                array_keys($this->plan->unassigned),
                array_values($this->plan->unassigned),
            ),
        ];
    }
}
