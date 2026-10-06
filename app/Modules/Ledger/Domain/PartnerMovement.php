<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Shared\Money\Money;

/** A franchise's ledger rows of one type over a month: debits (owed to HQ) and credits (owed to the franchise). */
final class PartnerMovement
{
    public function __construct(
        public readonly LedgerEntryType $entryType,
        public readonly Money $debit,
        public readonly Money $credit,
    ) {}
}
