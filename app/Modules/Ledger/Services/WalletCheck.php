<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Shared\Money\Money;

/** Whether a wholesale franchise's wallet covers a booking (shown on the quote screen). */
final class WalletCheck
{
    public function __construct(
        public readonly Money $balance,
        public readonly Money $creditLimit,
        public readonly Money $available,
        public readonly Money $required,
        public readonly bool $sufficient,
        /** Share of the credit limit in use; the desk is warned from 80% (spec §12 wallet auto-hold). */
        public readonly int $creditUsedPercent,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'balance' => $this->balance->toDecimalString(),
            'credit_limit' => $this->creditLimit->toDecimalString(),
            'available' => $this->available->toDecimalString(),
            'required' => $this->required->toDecimalString(),
            'sufficient' => $this->sufficient,
            'credit_used_percent' => $this->creditUsedPercent,
            'low_balance_warning' => $this->creditUsedPercent >= (int) config('pathology.ledger.credit_warning_percent'),
        ];
    }
}
