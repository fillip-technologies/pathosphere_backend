<?php

namespace App\Modules\Booking\Domain;

use App\Modules\Booking\Enums\OrderSource;
use App\Modules\Shared\Money\Money;

/**
 * When an order moves from draft to confirmed (spec §5.2: "invoice created
 * and payment rule met"):
 *
 * - B2B orders are billed on credit: confirmed at once.
 * - Home collections are paid at the visit: confirmed at once.
 * - Online orders must be paid in full first (payment link).
 * - Walk-ins and camps need the advance set in organization settings
 *   (100% unless HQ lowers it).
 */
final class PaymentRule
{
    public static function isMet(OrderSource $source, bool $billedOnCredit, Money $total, Money $paid, string $walkInAdvancePercent): bool
    {
        if ($billedOnCredit || $source === OrderSource::HomeCollection) {
            return true;
        }

        if ($source === OrderSource::Online) {
            return ! $paid->isLessThan($total);
        }

        return ! $paid->isLessThan($total->percent($walkInAdvancePercent));
    }
}
