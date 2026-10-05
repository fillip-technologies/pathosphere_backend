<?php

namespace Tests\Unit\Shared;

use App\Modules\Shared\Numbering\FinancialYear;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class FinancialYearTest extends TestCase
{
    public function test_april_starts_a_new_financial_year(): void
    {
        $year = FinancialYear::containing(CarbonImmutable::parse('2026-04-01 10:00', 'Asia/Kolkata'));

        $this->assertSame('2026-27', $year->label());
        $this->assertSame('26-27', $year->shortLabel());
    }

    public function test_march_belongs_to_the_previous_financial_year(): void
    {
        $year = FinancialYear::containing(CarbonImmutable::parse('2027-03-31 23:59', 'Asia/Kolkata'));

        $this->assertSame('2026-27', $year->label());
    }

    public function test_the_year_is_decided_in_india_time_not_utc(): void
    {
        // 31 March 20:00 UTC is already 1 April 01:30 in India.
        $year = FinancialYear::containing(CarbonImmutable::parse('2027-03-31 20:00', 'UTC'));

        $this->assertSame('2027-28', $year->label());
    }

    public function test_century_rollover_is_zero_padded(): void
    {
        $this->assertSame('2099-00', FinancialYear::containing(CarbonImmutable::parse('2099-06-01', 'Asia/Kolkata'))->label());
    }
}
