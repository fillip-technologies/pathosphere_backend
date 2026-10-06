<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\PaymentMode;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Money received (or refunded) on one business day at one branch from one
 * party in one mode.
 */
final class DailyCollection
{
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly string $branchId,
        /** Null for patients. */
        public readonly ?string $b2bClientId,
        /**
         * A franchise branch's patient paying online: the money lands in HQ's
         * gateway account although the sale is the franchise's.
         */
        public readonly bool $forFranchise,
        public readonly PaymentMode $mode,
        public readonly bool $isRefund,
        public readonly int $count,
        public readonly Money $amount,
    ) {}
}
