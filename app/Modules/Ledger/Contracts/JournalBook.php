<?php

namespace App\Modules\Ledger\Contracts;

use App\Modules\Ledger\Domain\JournalVoucher;
use Carbon\CarbonImmutable;

/** A period's vouchers, ready to be written for an accounting package. */
final class JournalBook
{
    /** @param  list<JournalVoucher>  $vouchers */
    public function __construct(
        public readonly string $companyName,
        public readonly string $currency,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly array $vouchers,
    ) {}
}
