<?php

namespace App\Modules\Locker\Contracts\Abdm;

/** Whether one care context reached the receiver, as reported to ABDM after a transfer. */
final class CareContextDelivery
{
    public function __construct(
        public readonly string $careContextReference,
        public readonly bool $delivered,
        public readonly string $description,
    ) {}
}
