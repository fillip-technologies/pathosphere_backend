<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Shared\Money\Money;
use InvalidArgumentException;

/** One side of a voucher: a positive amount debited or credited to one account. */
final class JournalLine
{
    private function __construct(
        public readonly BookAccount $account,
        public readonly Money $debit,
        public readonly Money $credit,
    ) {}

    public static function debit(BookAccount $account, Money $amount): self
    {
        self::assertPositive($amount);

        return new self($account, $amount, Money::zero());
    }

    public static function credit(BookAccount $account, Money $amount): self
    {
        self::assertPositive($amount);

        return new self($account, Money::zero(), $amount);
    }

    public function isDebit(): bool
    {
        return ! $this->debit->isZero();
    }

    public function amount(): Money
    {
        return $this->isDebit() ? $this->debit : $this->credit;
    }

    private static function assertPositive(Money $amount): void
    {
        if (! $amount->isGreaterThan(Money::zero())) {
            throw new InvalidArgumentException('Journal lines carry a positive amount.');
        }
    }
}
