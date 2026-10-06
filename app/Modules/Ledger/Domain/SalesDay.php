<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/** A branch's invoices of one day to one party (walk-in patients or a B2B client). */
final class SalesDay
{
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly string $branchCode,
        /** PAT for walk-in patients, else the client code. */
        public readonly string $partyCode,
        public readonly BookAccount $party,
        public readonly int $invoiceCount,
        public readonly Money $amount,
        public readonly Money $discount,
        public readonly Money $tax,
        public readonly Money $total,
    ) {}
}
