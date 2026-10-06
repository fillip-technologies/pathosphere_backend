<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/** Money a branch received (or refunded) on one day from one party in one payment mode. */
final class MoneyDay
{
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly string $branchCode,
        public readonly string $partyCode,
        public readonly BookAccount $party,
        /** cash, card, upi… */
        public readonly string $mode,
        public readonly BookAccount $moneyAccount,
        public readonly bool $isRefund,
        public readonly int $count,
        public readonly Money $amount,
    ) {}
}
