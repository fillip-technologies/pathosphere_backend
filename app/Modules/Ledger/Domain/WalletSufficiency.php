<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Shared\Money\Money;

/**
 * A wallet may go negative down to its credit limit, never further
 * (spec §7.1 franchises.credit_limit, WALLET_INSUFFICIENT).
 */
final class WalletSufficiency
{
    public static function available(Money $balance, Money $creditLimit): Money
    {
        return $balance->add($creditLimit);
    }

    public static function covers(Money $balance, Money $creditLimit, Money $charge): bool
    {
        return ! $charge->isGreaterThan(self::available($balance, $creditLimit));
    }

    /**
     * How much of the credit limit is in use, in whole percent (0 when there
     * is no limit or the balance is positive). Used for the 80% warning.
     */
    public static function creditUsedPercent(Money $balance, Money $creditLimit): int
    {
        if (! $balance->isNegative() || $creditLimit->isZero()) {
            return 0;
        }

        return intdiv(abs($balance->paise()) * 100, $creditLimit->paise());
    }
}
