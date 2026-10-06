<?php

namespace App\Modules\Booking\Services;

use App\Modules\Shared\Money\Money;

/** A franchise's patient billing over a settlement period. */
final class PartnerBilling
{
    public function __construct(
        /** Invoice totals (after discount) for patient orders not cancelled. */
        public readonly Money $netBilled,
        /** Patient money taken at the franchise's own desks (cash, card machine, UPI QR), less refunds paid back there. */
        public readonly Money $cashCollected,
    ) {}
}
