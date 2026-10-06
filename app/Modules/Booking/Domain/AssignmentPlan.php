<?php

namespace App\Modules\Booking\Domain;

/** What the route planner decided for a day's unassigned visits. */
final class AssignmentPlan
{
    /**
     * @param  list<PlannedAssignment>  $assigned
     * @param  array<string, string>  $unassigned  reason by visit ID (CollectionRoutePlanner::NO_*)
     */
    public function __construct(
        public readonly array $assigned,
        public readonly array $unassigned,
    ) {}
}
