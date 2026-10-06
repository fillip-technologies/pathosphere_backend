<?php

namespace App\Modules\Catalogue\Domain;

use App\Modules\Shared\Enums\Gender;

/**
 * Picks the reference range that applies to a patient (spec §7.4): ranges for
 * the patient's gender beat ranges for everyone, and a narrower age band beats
 * a wider one. When the age is unknown, age bands are ignored.
 */
final class ReferenceRangeSelector
{
    /** @param  list<ReferenceRangeEntry>  $ranges */
    public static function select(array $ranges, Gender $gender, ?int $ageDays): ?ReferenceRangeEntry
    {
        $candidates = array_values(array_filter(
            $ranges,
            fn (ReferenceRangeEntry $range): bool => self::fitsGender($range, $gender) && self::fitsAge($range, $ageDays),
        ));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (ReferenceRangeEntry $a, ReferenceRangeEntry $b): int => [self::genderRank($a), self::ageSpan($a)] <=> [self::genderRank($b), self::ageSpan($b)]);

        return $candidates[0];
    }

    private static function fitsGender(ReferenceRangeEntry $range, Gender $gender): bool
    {
        return $range->gender === null || $range->gender === $gender;
    }

    private static function fitsAge(ReferenceRangeEntry $range, ?int $ageDays): bool
    {
        if ($ageDays === null) {
            return true;
        }

        return ($range->ageMinDays === null || $ageDays >= $range->ageMinDays)
            && ($range->ageMaxDays === null || $ageDays <= $range->ageMaxDays);
    }

    /** 0 for a gender-specific range, 1 for one that applies to all. */
    private static function genderRank(ReferenceRangeEntry $range): int
    {
        return $range->gender === null ? 1 : 0;
    }

    private static function ageSpan(ReferenceRangeEntry $range): int
    {
        return ($range->ageMaxDays ?? PHP_INT_MAX >> 1) - ($range->ageMinDays ?? 0);
    }
}
