<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Domain\ReferenceRangeEntry;
use App\Modules\Catalogue\Domain\ReferenceRangeSelector;
use App\Modules\Catalogue\Enums\ResultType;
use App\Modules\Shared\Enums\Gender;

/** How one parameter is reported: type, unit, allowed options or formula, ranges. */
final class ParameterDefinition
{
    /**
     * @param  list<string>|null  $options
     * @param  list<ReferenceRangeEntry>  $ranges
     */
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $unit,
        public readonly ResultType $resultType,
        public readonly ?int $decimalPlaces,
        public readonly ?array $options,
        public readonly ?string $formula,
        public readonly int $displayOrder,
        public readonly array $ranges,
        /** For ABDM: each result is shared as a FHIR Observation coded by LOINC. */
        public readonly ?string $loincCode = null,
    ) {}

    public function rangeFor(Gender $gender, ?int $ageDays): ?ReferenceRangeEntry
    {
        return ReferenceRangeSelector::select($this->ranges, $gender, $ageDays);
    }

    public function isCalculated(): bool
    {
        return $this->resultType === ResultType::Calculated;
    }
}
