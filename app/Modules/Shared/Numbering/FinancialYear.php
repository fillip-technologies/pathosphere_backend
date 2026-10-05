<?php

namespace App\Modules\Shared\Numbering;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Indian financial year, April to March. Invoice series restart each year
 * (spec §6.9). The year is decided in Asia/Kolkata time, so an invoice raised
 * at 01:00 IST on 1 April belongs to the new year even though it is still
 * 31 March in UTC.
 */
final class FinancialYear
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    private function __construct(public readonly int $startYear) {}

    public static function containing(DateTimeInterface $moment): self
    {
        $local = CarbonImmutable::instance($moment)->setTimezone(self::BUSINESS_TIMEZONE);
        $startYear = $local->month >= 4 ? $local->year : $local->year - 1;

        return new self($startYear);
    }

    /** Stored in number_sequences.financial_year, e.g. "2026-27". */
    public function label(): string
    {
        return sprintf('%d-%02d', $this->startYear, ($this->startYear + 1) % 100);
    }

    /** Printed inside document numbers, e.g. "26-27". */
    public function shortLabel(): string
    {
        return sprintf('%02d-%02d', $this->startYear % 100, ($this->startYear + 1) % 100);
    }
}
