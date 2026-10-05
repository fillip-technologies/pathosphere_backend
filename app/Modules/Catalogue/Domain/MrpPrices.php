<?php

namespace App\Modules\Catalogue\Domain;

use App\Modules\Shared\Money\Money;

/**
 * Patient prices (spec §7.4): the branch's own MRP list item if it has one,
 * otherwise the organization's default MRP list, decided item by item.
 */
final class MrpPrices
{
    public function __construct(
        private readonly PriceBook $branchList,
        private readonly PriceBook $defaultList,
    ) {}

    public function forTest(string $testId): ?Money
    {
        return $this->branchList->testPrice($testId) ?? $this->defaultList->testPrice($testId);
    }

    public function forPackage(string $packageId): ?Money
    {
        return $this->branchList->packagePrice($packageId) ?? $this->defaultList->packagePrice($packageId);
    }
}
