<?php

namespace App\Modules\Booking\Services;

use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/** One company branch's invoices of one business day to one party: walk-in patients, or a B2B client. */
final class DailyBilling
{
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly string $branchId,
        /** Null for patients. */
        public readonly ?string $b2bClientId,
        public readonly int $invoiceCount,
        /** Gross before discount. */
        public readonly Money $amount,
        public readonly Money $discount,
        public readonly Money $tax,
        public readonly Money $total,
    ) {}
}
