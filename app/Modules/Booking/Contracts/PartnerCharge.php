<?php

namespace App\Modules\Booking\Contracts;

use App\Modules\Shared\Money\Money;

/** What a partner (franchise or B2B client) owes HQ for one order (spec §5.6). */
final class PartnerCharge
{
    /** @param  array<string, Money>  $partnerPriceByOrderItem */
    public function __construct(
        public readonly string $orderId,
        public readonly string $organizationId,
        public readonly ?string $franchiseId,
        public readonly ?string $b2bClientId,
        public readonly array $partnerPriceByOrderItem,
    ) {}

    public function total(): Money
    {
        return array_reduce($this->partnerPriceByOrderItem, fn (Money $sum, Money $price) => $sum->add($price), Money::zero());
    }

    public function isCompanyOrder(): bool
    {
        return $this->franchiseId === null && $this->b2bClientId === null;
    }
}
