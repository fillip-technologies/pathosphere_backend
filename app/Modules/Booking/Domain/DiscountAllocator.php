<?php

namespace App\Modules\Booking\Domain;

use App\Modules\Shared\Money\Money;
use InvalidArgumentException;

/**
 * Spreads a bill-level discount over the priced lines in proportion to their
 * price, in whole paise, so the parts always add up to the discount exactly
 * (largest-remainder method). Each line's net price then stays right for
 * franchise commissions (spec §5.6).
 */
final class DiscountAllocator
{
    /**
     * @param  list<Money>  $linePrices
     * @return list<Money> one discount per line, in the same order
     */
    public static function allocate(Money $discount, array $linePrices): array
    {
        $gross = array_sum(array_map(fn (Money $price) => $price->paise(), $linePrices));

        if ($discount->isNegative() || $discount->paise() > $gross) {
            throw new InvalidArgumentException('The discount must be between zero and the total of the lines.');
        }

        if ($discount->isZero() || $gross === 0) {
            return array_map(fn () => Money::zero(), $linePrices);
        }

        $shares = [];
        $remainders = [];
        foreach ($linePrices as $index => $price) {
            $exact = $discount->paise() * $price->paise();
            $shares[$index] = intdiv($exact, $gross);
            $remainders[$index] = $exact % $gross;
        }

        // Hand the leftover paise to the lines that lost the most to rounding.
        arsort($remainders);
        $leftover = $discount->paise() - array_sum($shares);
        foreach (array_keys($remainders) as $index) {
            if ($leftover === 0) {
                break;
            }
            $shares[$index]++;
            $leftover--;
        }

        return array_map(fn (int $paise) => Money::fromPaise($paise), $shares);
    }

    /** Whether the discount is above the share of the bill a front desk may give alone. */
    public static function needsApproval(Money $discount, Money $gross, string $limitPercent): bool
    {
        return $discount->isGreaterThan($gross->percent($limitPercent));
    }
}
