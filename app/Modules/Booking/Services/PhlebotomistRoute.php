<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Domain\PlannedStop;
use App\Modules\Booking\Models\HomeCollection;
use App\Modules\Network\Services\BranchLocation;
use Carbon\CarbonImmutable;

/** A phlebotomist's day: the visits still to make, in order, from their branch. */
final class PhlebotomistRoute
{
    /**
     * @param  list<PlannedStop>  $stops
     * @param  array<string, HomeCollection>  $visits  by ID
     */
    public function __construct(
        public readonly string $phlebotomistId,
        public readonly CarbonImmutable $date,
        public readonly ?BranchLocation $start,
        public readonly array $stops,
        public readonly array $visits,
    ) {}
}
