<?php

namespace App\Modules\Booking\Services;

use App\Modules\Shared\Money\Money;

/** One branch's booking and billing on one business day, for the nightly dashboard summary. */
final class BranchBookingDay
{
    public function __construct(
        public int $ordersBooked = 0,
        public int $ordersCancelled = 0,
        public int $testsOrdered = 0,
        public ?Money $grossBilling = null,
        public ?Money $collected = null,
    ) {
        $this->grossBilling ??= Money::zero();
        $this->collected ??= Money::zero();
    }
}
