<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Shared\Money\Money;

/** An order item already charged to a partner, as found in the ledger. */
final class ChargedItem
{
    public function __construct(
        public readonly string $orderItemId,
        public readonly Money $amount,
    ) {}
}
