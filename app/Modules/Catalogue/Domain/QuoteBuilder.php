<?php

namespace App\Modules\Catalogue\Domain;

use App\Modules\Shared\Money\Money;

/**
 * Prices and routes the items of a booking (spec §5.2 step 2, §7.4), without
 * touching the database: everything it needs is passed in.
 *
 * Per item:
 * - MRP from the branch list, else the default list;
 * - partner price from the franchise / B2B client list when there is one
 *   (company-owned walk-ins have none and pay partner price 0);
 * - packages expand into child test lines priced 0;
 * - every test line gets a processing lab.
 *
 * Every problem is collected, not just the first, so the booking screen can
 * show them all at once.
 */
final class QuoteBuilder
{
    /** @var list<QuoteProblem> */
    private array $problems = [];

    /** @var array<string, true> test IDs already on the quote */
    private array $testsSeen = [];

    /**
     * @param  array<string, TestEntry>  $tests
     * @param  array<string, PackageEntry>  $packages
     * @param  PriceBook|null  $partnerPrices  null when the booking branch charges no partner price
     * @param  list<RoutingRuleEntry>  $routingRules  active rules of the source branch
     * @param  array<string, array<string, true>>  $capableLabs  operating lab ID => test IDs it can run
     */
    public function __construct(
        private readonly array $tests,
        private readonly array $packages,
        private readonly MrpPrices $mrpPrices,
        private readonly ?PriceBook $partnerPrices,
        private readonly string $sourceBranchId,
        private readonly array $routingRules,
        private readonly array $capableLabs,
    ) {}

    /**
     * @param  list<QuoteRequestItem>  $items
     */
    public function build(array $items): Quote
    {
        $this->problems = [];
        $this->testsSeen = [];
        $lines = [];

        foreach ($items as $index => $item) {
            $line = $item->testId !== null
                ? $this->testLine($index, $item->testId)
                : $this->packageLine($index, (string) $item->packageId);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return new Quote($lines, $this->problems);
    }

    private function testLine(int $index, string $testId): ?QuoteLine
    {
        $test = $this->tests[$testId] ?? null;

        if ($test === null) {
            return $this->problem($index, QuoteProblem::ITEM_NOT_FOUND, 'This test does not exist.', testId: $testId);
        }

        if (! $test->isActive) {
            return $this->problem($index, QuoteProblem::TEST_INACTIVE, "{$test->name} is not currently offered.", testId: $testId);
        }

        $mrp = $this->mrpPrices->forTest($testId);
        $partner = $this->partnerPrices === null ? Money::zero() : $this->partnerPrices->testPrice($testId);

        if ($mrp === null || $partner === null) {
            return $this->problem($index, QuoteProblem::PRICE_MISSING, $this->priceMissingMessage($test->name, $mrp === null), testId: $testId);
        }

        return $this->routedTestLine($index, $test, $mrp, $partner);
    }

    private function packageLine(int $index, string $packageId): ?QuoteLine
    {
        $package = $this->packages[$packageId] ?? null;

        if ($package === null) {
            return $this->problem($index, QuoteProblem::ITEM_NOT_FOUND, 'This package does not exist.', packageId: $packageId);
        }

        if (! $package->isActive) {
            return $this->problem($index, QuoteProblem::PACKAGE_INACTIVE, "{$package->name} is not currently offered.", packageId: $packageId);
        }

        $mrp = $this->mrpPrices->forPackage($packageId);
        $partner = $this->partnerPrices === null ? Money::zero() : $this->partnerPrices->packagePrice($packageId);

        if ($mrp === null || $partner === null) {
            return $this->problem($index, QuoteProblem::PRICE_MISSING, $this->priceMissingMessage($package->name, $mrp === null), packageId: $packageId);
        }

        $children = [];
        foreach ($package->tests as $test) {
            $child = $this->routedTestLine($index, $test, Money::zero(), Money::zero());

            if ($child !== null) {
                $children[] = $child;
            }
        }

        return new QuoteLine(QuoteLine::PACKAGE, $package->id, $package->code, $package->name, $mrp, $partner, children: $children);
    }

    private function routedTestLine(int $index, TestEntry $test, Money $mrp, Money $partner): ?QuoteLine
    {
        if (isset($this->testsSeen[$test->id])) {
            return $this->problem($index, QuoteProblem::DUPLICATE_TEST, "{$test->name} is already on this booking.", testId: $test->id);
        }
        $this->testsSeen[$test->id] = true;

        $processingBranchId = ProcessingBranchResolver::resolve($this->sourceBranchId, $test->id, $this->routingRules, $this->capableLabs);

        if ($processingBranchId === null) {
            return $this->problem($index, QuoteProblem::NO_ROUTE_FOR_TEST, "No lab can currently run {$test->name} for this branch.", testId: $test->id);
        }

        return new QuoteLine(
            QuoteLine::TEST,
            $test->id,
            $test->code,
            $test->name,
            $mrp,
            $partner,
            $processingBranchId,
            $test->tatHours,
            $test->sampleType,
            $test->containerType,
        );
    }

    private function priceMissingMessage(string $itemName, bool $mrpMissing): string
    {
        return $mrpMissing
            ? "{$itemName} has no patient price (MRP) on this branch's price lists."
            : "{$itemName} has no price on the partner's price list.";
    }

    /** Records a problem; returns null so callers can `return $this->problem(...)`. */
    private function problem(int $index, string $code, string $message, ?string $testId = null, ?string $packageId = null): null
    {
        $this->problems[] = new QuoteProblem($code, $message, $index, $testId, $packageId);

        return null;
    }
}
