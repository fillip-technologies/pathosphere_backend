<?php

namespace App\Modules\Booking\Contracts;

/**
 * Posts partner charges when orders are confirmed or cancelled (spec §5.2
 * steps 4–5, §5.6). Booking calls this inside its transaction, so a failure
 * (e.g. WALLET_INSUFFICIENT in Phase 6) blocks the order.
 */
interface PartnerChargePolicy
{
    public function chargeForConfirmedOrder(PartnerCharge $charge): void;

    public function reverseForCancelledOrder(PartnerCharge $charge): void;
}
