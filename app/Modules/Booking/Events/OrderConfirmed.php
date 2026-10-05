<?php

namespace App\Modules\Booking\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Raised once an order is confirmed, after the booking transaction commits (spec §9). */
final class OrderConfirmed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $organizationId,
    ) {}
}
