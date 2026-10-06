<?php

namespace App\Modules\Booking\Domain;

final class PlannedAssignment
{
    public function __construct(
        public readonly string $visitId,
        public readonly string $phlebotomistId,
        /** Road distance this visit adds to the phlebotomist's day. */
        public readonly int $addedMetres,
    ) {}
}
