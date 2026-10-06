<?php

namespace App\Modules\Locker\Contracts\Abdm;

/** A patient (by our reference, the UHID) and the care contexts offered or linked. */
final class PatientCareContexts
{
    /** @param  list<CareContext>  $careContexts */
    public function __construct(
        public readonly string $reference,
        public readonly string $display,
        public readonly array $careContexts,
    ) {}
}
