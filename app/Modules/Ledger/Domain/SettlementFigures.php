<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Ledger\Enums\SettlementDirection;
use App\Modules\Shared\Money\Money;

final class SettlementFigures
{
    public function __construct(
        public readonly Money $grossBilling,
        public readonly Money $partnerShare,
        public readonly Money $hqShare,
        public readonly Money $tax,
        public readonly Money $netAmount,
        public readonly SettlementDirection $direction,
    ) {}
}
