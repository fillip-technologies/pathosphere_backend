<?php

namespace App\Modules\Booking\Domain;

use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/** An order item about to be saved, built from a quote line. */
final class OrderLineDraft
{
    /** @param  list<OrderLineDraft>  $children */
    public function __construct(
        public readonly ?string $testId,
        public readonly ?string $packageId,
        public readonly ?string $processingBranchId,
        public readonly Money $mrpPrice,
        public readonly Money $partnerPrice,
        public readonly Money $discount,
        public readonly ?CarbonImmutable $dueAt,
        public readonly array $children = [],
    ) {}

    public function netPrice(): Money
    {
        return $this->mrpPrice->subtract($this->discount);
    }
}
