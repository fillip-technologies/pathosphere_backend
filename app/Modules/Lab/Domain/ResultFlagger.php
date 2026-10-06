<?php

namespace App\Modules\Lab\Domain;

use App\Modules\Catalogue\Domain\ReferenceRangeEntry;
use App\Modules\Lab\Enums\ResultFlag;
use Brick\Math\BigDecimal;

/**
 * Flags a numeric result against its reference range (spec §5.5 step 1).
 * Critical limits win over normal limits; a value exactly on a limit is
 * inside it.
 */
final class ResultFlagger
{
    public static function flag(?string $numericValue, ?ReferenceRangeEntry $range): ?ResultFlag
    {
        if ($numericValue === null || $range === null) {
            return null;
        }

        $value = BigDecimal::of($numericValue);

        return match (true) {
            self::below($value, $range->criticalLow) => ResultFlag::CriticalLow,
            self::above($value, $range->criticalHigh) => ResultFlag::CriticalHigh,
            self::below($value, $range->refLow) => ResultFlag::Low,
            self::above($value, $range->refHigh) => ResultFlag::High,
            default => ResultFlag::Normal,
        };
    }

    private static function below(BigDecimal $value, ?string $limit): bool
    {
        return $limit !== null && $value->isLessThan(BigDecimal::of($limit));
    }

    private static function above(BigDecimal $value, ?string $limit): bool
    {
        return $limit !== null && $value->isGreaterThan(BigDecimal::of($limit));
    }
}
