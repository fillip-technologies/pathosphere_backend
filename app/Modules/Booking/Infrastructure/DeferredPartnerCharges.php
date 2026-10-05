<?php

namespace App\Modules\Booking\Infrastructure;

use App\Modules\Booking\Contracts\PartnerCharge;
use App\Modules\Booking\Contracts\PartnerChargePolicy;

/**
 * Phase 3 placeholder: the partner ledger and wallets arrive in Phase 6.
 * Partner prices are already snapshotted on order items, so Phase 6 can post
 * charges for orders booked before it exists.
 */
final class DeferredPartnerCharges implements PartnerChargePolicy
{
    public function chargeForConfirmedOrder(PartnerCharge $charge): void {}

    public function reverseForCancelledOrder(PartnerCharge $charge): void {}
}
