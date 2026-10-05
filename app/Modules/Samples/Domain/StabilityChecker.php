<?php

namespace App\Modules\Samples\Domain;

use Carbon\CarbonImmutable;

/**
 * Transit limits (spec §7.4 `stability_hours`). A container is only as stable
 * as its most fragile test; tests without a limit do not constrain it.
 */
final class StabilityChecker
{
    /**
     * @param  list<int|null>  $stabilityHours  one entry per test in the container
     * @return CarbonImmutable|null null when no test has a limit
     */
    public static function stableUntil(CarbonImmutable $collectedAt, array $stabilityHours): ?CarbonImmutable
    {
        $limits = array_filter($stabilityHours, fn (?int $hours): bool => $hours !== null);

        return $limits === [] ? null : $collectedAt->addHours(min($limits));
    }

    public static function isExceeded(?CarbonImmutable $stableUntil, CarbonImmutable $at): bool
    {
        return $stableUntil !== null && $at->greaterThan($stableUntil);
    }
}
