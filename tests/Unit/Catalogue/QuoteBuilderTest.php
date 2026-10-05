<?php

namespace Tests\Unit\Catalogue;

use App\Modules\Catalogue\Domain\MrpPrices;
use App\Modules\Catalogue\Domain\PackageEntry;
use App\Modules\Catalogue\Domain\PriceBook;
use App\Modules\Catalogue\Domain\QuoteBuilder;
use App\Modules\Catalogue\Domain\QuoteLine;
use App\Modules\Catalogue\Domain\QuoteProblem;
use App\Modules\Catalogue\Domain\QuoteRequestItem;
use App\Modules\Catalogue\Domain\RoutingRuleEntry;
use App\Modules\Catalogue\Domain\TestEntry;
use App\Modules\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

/** Pricing, package expansion and routing of a booking (spec §5.2, §7.4). */
final class QuoteBuilderTest extends TestCase
{
    private TestEntry $cbc;

    private TestEntry $glucose;

    private TestEntry $vitaminD;

    private PackageEntry $healthCheck;

    protected function setUp(): void
    {
        $this->cbc = new TestEntry('cbc', 'CBC', 'Complete Blood Count', true, 6, 'EDTA whole blood', 'Lavender top');
        $this->glucose = new TestEntry('glu', 'GLU-F', 'Glucose Fasting', true, 4, 'Fluoride plasma', 'Grey top');
        $this->vitaminD = new TestEntry('vitd', 'VITD', 'Vitamin D', true, 24, 'Serum', 'Red top');
        $this->healthCheck = new PackageEntry('basic', 'PKG-BASIC', 'Basic Health Check', true, [$this->cbc, $this->glucose]);
    }

    public function test_company_walk_in_pays_mrp_and_no_partner_price(): void
    {
        $quote = $this->builder(partner: null)->build([QuoteRequestItem::test('cbc')]);

        $this->assertTrue($quote->isBookable());
        $line = $quote->lines[0];
        $this->assertSame('350.00', (string) $line->mrpPrice);
        $this->assertSame('0.00', (string) $line->partnerPrice);
        $this->assertSame('clinical-lab', $line->processingBranchId);
        $this->assertSame(6, $line->tatHours);
    }

    public function test_the_branch_list_overrides_the_default_list_item_by_item(): void
    {
        $branchList = new PriceBook('branch', ['cbc' => Money::fromString('300')], []);

        $quote = $this->builder(mrp: new MrpPrices($branchList, $this->defaultList()))
            ->build([QuoteRequestItem::test('cbc'), QuoteRequestItem::test('glu')]);

        $this->assertSame('300.00', (string) $quote->lines[0]->mrpPrice);
        $this->assertSame('120.00', (string) $quote->lines[1]->mrpPrice);
        $this->assertSame('420.00', (string) $quote->mrpTotal());
    }

    public function test_franchise_bookings_carry_the_partner_price(): void
    {
        $partner = new PriceBook('partner', ['cbc' => Money::fromString('210')], ['basic' => Money::fromString('500')]);

        $quote = $this->builder(partner: $partner)->build([QuoteRequestItem::test('cbc')]);

        $this->assertSame('210.00', (string) $quote->partnerTotal());
    }

    public function test_a_missing_partner_price_blocks_the_item(): void
    {
        $partner = new PriceBook('partner', [], []);

        $quote = $this->builder(partner: $partner)->build([QuoteRequestItem::test('cbc')]);

        $this->assertFalse($quote->isBookable());
        $this->assertSame(QuoteProblem::PRICE_MISSING, $quote->problems[0]->code);
    }

    public function test_packages_expand_into_routed_child_tests_priced_zero(): void
    {
        $quote = $this->builder()->build([QuoteRequestItem::package('basic')]);

        $package = $quote->lines[0];
        $this->assertSame(QuoteLine::PACKAGE, $package->lineType);
        $this->assertSame('799.00', (string) $package->mrpPrice);
        $this->assertNull($package->processingBranchId);
        $this->assertCount(2, $package->children);
        $this->assertSame('0.00', (string) $package->children[0]->mrpPrice);
        $this->assertSame('clinical-lab', $package->children[0]->processingBranchId);
        $this->assertSame('799.00', (string) $quote->mrpTotal());
    }

    public function test_each_test_is_routed_on_its_own(): void
    {
        $quote = $this->builder()->build([QuoteRequestItem::test('cbc'), QuoteRequestItem::test('vitd')]);

        $this->assertSame('clinical-lab', $quote->lines[0]->processingBranchId);
        $this->assertSame('reference-lab', $quote->lines[1]->processingBranchId);
    }

    public function test_all_problems_are_reported_together(): void
    {
        $inactive = new TestEntry('old', 'OLD', 'Retired Test', false, 6, 'Serum', 'Red top');
        $unrouted = new TestEntry('rare', 'RARE', 'Rare Test', true, 72, 'Serum', 'Red top');

        $quote = $this->builder(extraTests: [$inactive, $unrouted], extraPrices: ['rare' => '900'])->build([
            QuoteRequestItem::test('missing'),
            QuoteRequestItem::test('old'),
            QuoteRequestItem::test('rare'),
            QuoteRequestItem::test('cbc'),
        ]);

        $this->assertSame(
            [[0, QuoteProblem::ITEM_NOT_FOUND], [1, QuoteProblem::TEST_INACTIVE], [2, QuoteProblem::NO_ROUTE_FOR_TEST]],
            array_map(fn (QuoteProblem $problem) => [$problem->itemIndex, $problem->code], $quote->problems),
        );
        $this->assertCount(1, $quote->lines);
    }

    public function test_a_test_cannot_be_booked_twice_even_inside_a_package(): void
    {
        $quote = $this->builder()->build([QuoteRequestItem::test('cbc'), QuoteRequestItem::package('basic')]);

        $this->assertSame(QuoteProblem::DUPLICATE_TEST, $quote->problems[0]->code);
        $this->assertSame(1, $quote->problems[0]->itemIndex);
    }

    private function defaultList(): PriceBook
    {
        return new PriceBook('default', [
            'cbc' => Money::fromString('350'),
            'glu' => Money::fromString('120'),
            'vitd' => Money::fromString('1400'),
        ], ['basic' => Money::fromString('799')]);
    }

    /**
     * @param  list<TestEntry>  $extraTests
     * @param  array<string, string>  $extraPrices
     */
    private function builder(?MrpPrices $mrp = null, ?PriceBook $partner = null, array $extraTests = [], array $extraPrices = []): QuoteBuilder
    {
        $tests = [];
        foreach ([$this->cbc, $this->glucose, $this->vitaminD, ...$extraTests] as $test) {
            $tests[$test->id] = $test;
        }

        if ($mrp === null) {
            $defaultPrices = ['cbc' => '350', 'glu' => '120', 'vitd' => '1400', ...$extraPrices];
            $mrp = new MrpPrices(PriceBook::none(), new PriceBook('default', array_map(fn ($p) => Money::fromString($p), $defaultPrices), ['basic' => Money::fromString('799')]));
        }

        return new QuoteBuilder(
            $tests,
            ['basic' => $this->healthCheck],
            $mrp,
            $partner,
            'psc',
            [new RoutingRuleEntry(null, 'clinical-lab', 1), new RoutingRuleEntry('vitd', 'reference-lab', 1)],
            [
                'clinical-lab' => ['cbc' => true, 'glu' => true],
                'reference-lab' => ['cbc' => true, 'glu' => true, 'vitd' => true, 'old' => true],
            ],
        );
    }
}
