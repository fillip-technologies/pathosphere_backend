<?php

namespace Tests\Feature\Shared;

use App\Modules\Shared\Numbering\FinancialYear;
use App\Modules\Shared\Numbering\NumberSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class NumberSequenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private NumberSequenceService $sequences;

    private string $organizationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sequences = app(NumberSequenceService::class);
        $this->organizationId = (string) Str::uuid();
    }

    public function test_numbers_increase_by_one_per_series(): void
    {
        DB::transaction(function (): void {
            $this->assertSame(1, $this->sequences->next($this->organizationId, 'uhid'));
            $this->assertSame(2, $this->sequences->next($this->organizationId, 'uhid'));
            $this->assertSame(1, $this->sequences->next($this->organizationId, 'barcode'));
        });
    }

    public function test_invoice_series_restart_each_financial_year(): void
    {
        $thisYear = FinancialYear::containing(CarbonImmutable::parse('2026-10-05'));
        $nextYear = FinancialYear::containing(CarbonImmutable::parse('2027-04-01'));

        DB::transaction(function () use ($thisYear, $nextYear): void {
            $this->sequences->next($this->organizationId, 'invoice:PAT01', $thisYear);
            $this->assertSame(2, $this->sequences->next($this->organizationId, 'invoice:PAT01', $thisYear));
            $this->assertSame(1, $this->sequences->next($this->organizationId, 'invoice:PAT01', $nextYear));
        });
    }

    public function test_formatted_numbers_include_the_short_financial_year(): void
    {
        $year = FinancialYear::containing(CarbonImmutable::parse('2026-10-05'));

        $number = DB::transaction(fn () => $this->sequences->nextFormatted(
            $this->organizationId,
            'invoice:PAT01',
            'INV/{branch_code}/{FY}/{seq:5}',
            ['branch_code' => 'PAT01'],
            $year,
        ));

        $this->assertSame('INV/PAT01/26-27/00001', $number);
    }

    public function test_a_rolled_back_transaction_releases_its_number(): void
    {
        try {
            DB::transaction(function (): void {
                $this->sequences->next($this->organizationId, 'order');
                throw new \RuntimeException('order save failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, DB::transaction(fn () => $this->sequences->next($this->organizationId, 'order')));
    }

    public function test_it_refuses_to_run_outside_a_transaction(): void
    {
        // RefreshDatabase wraps each test in a transaction; leave it to test the guard.
        DB::rollBack();

        try {
            $this->expectException(LogicException::class);
            $this->sequences->next($this->organizationId, 'uhid');
        } finally {
            DB::beginTransaction();
        }
    }
}
