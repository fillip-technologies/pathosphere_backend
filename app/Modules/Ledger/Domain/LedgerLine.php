<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Shared\Money\Money;

/** A ledger row as the settlement maths needs it. */
final class LedgerLine
{
    public function __construct(
        public readonly LedgerEntryType $entryType,
        public readonly Money $debit,
        public readonly Money $credit,
    ) {}
}
