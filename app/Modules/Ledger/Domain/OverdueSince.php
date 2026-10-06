<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Since when a partner has been past its credit limit without a break, for
 * the auto-hold grace period (spec §9 franchise auto-hold).
 */
final class OverdueSince
{
    /**
     * @param  list<array{balance_after: Money, created_at: CarbonImmutable}>  $newestFirst  ledger rows, newest first
     * @return CarbonImmutable|null when the current run past the limit began; null if within the limit now
     */
    public static function find(array $newestFirst, Money $creditLimit): ?CarbonImmutable
    {
        $floor = Money::zero()->subtract($creditLimit);
        $since = null;

        foreach ($newestFirst as $row) {
            if (! $row['balance_after']->isLessThan($floor)) {
                break;
            }

            $since = $row['created_at'];
        }

        return $since;
    }
}
