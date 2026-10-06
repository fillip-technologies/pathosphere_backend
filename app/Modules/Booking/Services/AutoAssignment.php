<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Domain\AssignmentPlan;
use Carbon\CarbonImmutable;

/** The outcome of assigning a branch's day of home visits by distance. */
final class AutoAssignment
{
    public function __construct(
        public readonly string $branchId,
        public readonly CarbonImmutable $date,
        public readonly AssignmentPlan $plan,
    ) {}
}
