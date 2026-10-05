<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Domain\MrpPrices;
use App\Modules\Catalogue\Domain\PackageEntry;
use App\Modules\Catalogue\Domain\PriceBook;
use App\Modules\Catalogue\Domain\Quote;
use App\Modules\Catalogue\Domain\QuoteBuilder;
use App\Modules\Catalogue\Domain\QuoteRequestItem;
use App\Modules\Catalogue\Domain\RoutingRuleEntry;
use App\Modules\Catalogue\Domain\TestEntry;
use App\Modules\Catalogue\Errors\CatalogueError;
use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Catalogue\Models\LabTestCapability;
use App\Modules\Catalogue\Models\Package;
use App\Modules\Catalogue\Models\PriceList;
use App\Modules\Catalogue\Models\PriceListItem;
use App\Modules\Catalogue\Models\RoutingRule;
use App\Modules\Network\Services\BranchPricingProfile;
use App\Modules\Network\Services\NetworkDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Prices and routes a prospective booking without saving anything
 * (POST /order-quotes, spec §8). Booking in Phase 3 calls the same service,
 * so a quote and the order it becomes always agree.
 *
 * This class only loads data; every decision is made by QuoteBuilder.
 */
final class OrderQuoteService
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public function __construct(private readonly NetworkDirectory $network) {}

    /**
     * @param  list<QuoteRequestItem>  $items
     * @return array{quote: Quote, branch: BranchPricingProfile, mrp_price_list_id: string, partner_price_list_id: string|null}
     */
    public function quote(string $branchId, ?string $b2bClientId, array $items): array
    {
        $branch = $this->network->pricingProfile($branchId)
            ?? throw ValidationException::withMessages(['branch_id' => 'The selected branch does not exist.']);

        if (! $branch->isOperating()) {
            throw CatalogueError::branchNotOperating();
        }

        $partnerPriceListId = $this->partnerPriceListId($branch, $b2bClientId);
        $today = CarbonImmutable::now(self::BUSINESS_TIMEZONE)->startOfDay();
        $defaultMrpList = PriceList::query()->inEffectOn($today)->where('is_default_mrp', true)->first()
            ?? throw CatalogueError::noDefaultMrpList();

        [$tests, $packages] = $this->catalogueEntries($items);
        $testIds = array_keys($tests);
        $packageIds = array_keys($packages);

        $builder = new QuoteBuilder(
            $tests,
            $packages,
            new MrpPrices(
                $this->priceBook($this->listInEffect($branch->mrpPriceListId, $today), $testIds, $packageIds),
                $this->priceBook($defaultMrpList->id, $testIds, $packageIds),
            ),
            $partnerPriceListId === null ? null : $this->priceBook($this->listInEffect($partnerPriceListId, $today), $testIds, $packageIds),
            $branch->branchId,
            $this->routingRules($branch->branchId),
            $this->capableLabs($branch->organizationId, $testIds),
        );

        return [
            'quote' => $builder->build($items),
            'branch' => $branch,
            'mrp_price_list_id' => $branch->mrpPriceListId ?? $defaultMrpList->id,
            'partner_price_list_id' => $partnerPriceListId,
        ];
    }

    /** B2B bookings use the client's list; franchise bookings the franchise's partner list. */
    private function partnerPriceListId(BranchPricingProfile $branch, ?string $b2bClientId): ?string
    {
        if ($b2bClientId === null) {
            return $branch->partnerPriceListId;
        }

        return $this->network->b2bClientPriceListId($b2bClientId)
            ?? throw ValidationException::withMessages(['b2b_client_id' => 'The selected B2B client does not exist.']);
    }

    /**
     * Requested tests, plus the tests inside requested packages.
     *
     * @param  list<QuoteRequestItem>  $items
     * @return array{array<string, TestEntry>, array<string, PackageEntry>}
     */
    private function catalogueEntries(array $items): array
    {
        $requestedTestIds = array_values(array_filter(array_map(fn (QuoteRequestItem $item) => $item->testId, $items)));
        $requestedPackageIds = array_values(array_filter(array_map(fn (QuoteRequestItem $item) => $item->packageId, $items)));

        $packages = [];
        foreach (Package::query()->with('tests')->whereKey($requestedPackageIds)->get() as $package) {
            $packages[$package->id] = new PackageEntry(
                $package->id,
                $package->code,
                $package->name,
                $package->is_active,
                $package->tests->map(fn (LabTest $test) => $this->testEntry($test))->values()->all(),
            );
        }

        $tests = [];
        foreach (LabTest::query()->whereKey($requestedTestIds)->get() as $test) {
            $tests[$test->id] = $this->testEntry($test);
        }
        foreach ($packages as $package) {
            foreach ($package->tests as $test) {
                $tests[$test->id] ??= $test;
            }
        }

        return [$tests, $packages];
    }

    private function testEntry(LabTest $test): TestEntry
    {
        return new TestEntry($test->id, $test->code, $test->name, $test->is_active, $test->tat_hours, $test->sample_type, $test->container_type);
    }

    /** The list's ID if it is active and valid today, otherwise null (no prices). */
    private function listInEffect(?string $priceListId, CarbonImmutable $today): ?string
    {
        if ($priceListId === null) {
            return null;
        }

        return PriceList::query()->inEffectOn($today)->whereKey($priceListId)->value('id');
    }

    /**
     * @param  list<string>  $testIds
     * @param  list<string>  $packageIds
     */
    private function priceBook(?string $priceListId, array $testIds, array $packageIds): PriceBook
    {
        if ($priceListId === null) {
            return PriceBook::none();
        }

        $testPrices = [];
        $packagePrices = [];

        $items = PriceListItem::query()
            ->where('price_list_id', $priceListId)
            ->where(fn ($query) => $query->whereIn('test_id', $testIds)->orWhereIn('package_id', $packageIds))
            ->get();

        foreach ($items as $item) {
            if ($item->test_id !== null) {
                $testPrices[$item->test_id] = $item->price;
            } else {
                $packagePrices[(string) $item->package_id] = $item->price;
            }
        }

        return new PriceBook($priceListId, $testPrices, $packagePrices);
    }

    /** @return list<RoutingRuleEntry> */
    private function routingRules(string $sourceBranchId): array
    {
        return RoutingRule::query()
            ->where('source_branch_id', $sourceBranchId)
            ->where('is_active', true)
            ->get()
            ->map(fn (RoutingRule $rule) => new RoutingRuleEntry($rule->test_id, $rule->processing_branch_id, $rule->priority))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $testIds
     * @return array<string, array<string, true>>
     */
    private function capableLabs(string $organizationId, array $testIds): array
    {
        $capable = [];

        LabTestCapability::query()
            ->whereIn('branch_id', $this->network->operatingLabIds($organizationId))
            ->whereIn('test_id', $testIds)
            ->where('is_active', true)
            ->get(['branch_id', 'test_id'])
            ->each(function (LabTestCapability $capability) use (&$capable): void {
                $capable[$capability->branch_id][$capability->test_id] = true;
            });

        return $capable;
    }
}
