<?php

namespace App\Modules\Booking\Domain;

/** One stop of a planned route: the leg that reaches it and when the visit starts. */
final class PlannedStop
{
    public function __construct(
        public readonly RouteStop $stop,
        public readonly int $legMetres,
        /** Arrival, or the slot start when the phlebotomist arrives early and waits. */
        public readonly int $visitStartsAt,
        public readonly bool $onTime,
    ) {}
}
