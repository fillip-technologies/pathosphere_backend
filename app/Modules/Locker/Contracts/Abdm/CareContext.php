<?php

namespace App\Modules\Locker\Contracts\Abdm;

/** One of our reports as ABDM lists it: our reference and the name the patient sees. */
final class CareContext
{
    public function __construct(
        public readonly string $reference,
        public readonly string $display,
    ) {}
}
