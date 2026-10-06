<?php

namespace App\Modules\Locker\Domain;

/** One value of one parameter on one report, for a trend chart. */
final class TrendPoint
{
    public function __construct(
        public readonly string $recordDate,
        public readonly string $medicalRecordId,
        public readonly string $parameterName,
        public readonly ?string $value,
        /** Decimal string; null for text results such as "Reactive". */
        public readonly ?string $valueNumeric,
        public readonly ?string $unit,
        public readonly ?string $referenceRange,
        public readonly ?string $flag,
        public readonly string $labName,
    ) {}
}
