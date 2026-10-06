<?php

namespace App\Modules\Catalogue\Domain;

use App\Modules\Shared\Enums\Gender;

/**
 * Normal and critical limits for one parameter, for one gender and age band.
 * Limits are decimal strings; null means "no limit".
 */
final class ReferenceRangeEntry
{
    public function __construct(
        public readonly ?Gender $gender,
        public readonly ?int $ageMinDays,
        public readonly ?int $ageMaxDays,
        public readonly ?string $refLow,
        public readonly ?string $refHigh,
        public readonly ?string $criticalLow,
        public readonly ?string $criticalHigh,
        public readonly ?string $displayText,
    ) {}

    /** What the report prints as the reference interval, e.g. "12 - 15". */
    public function printedText(): string
    {
        if ($this->displayText !== null && $this->displayText !== '') {
            return $this->displayText;
        }

        return match (true) {
            $this->refLow !== null && $this->refHigh !== null => "{$this->refLow} - {$this->refHigh}",
            $this->refLow !== null => ">= {$this->refLow}",
            $this->refHigh !== null => "<= {$this->refHigh}",
            default => '',
        };
    }
}
