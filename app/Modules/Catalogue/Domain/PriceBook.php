<?php

namespace App\Modules\Catalogue\Domain;

use App\Modules\Shared\Money\Money;

/** The prices on one price list, keyed by test and package ID. */
final class PriceBook
{
    /**
     * @param  array<string, Money>  $testPrices
     * @param  array<string, Money>  $packagePrices
     */
    public function __construct(
        public readonly ?string $priceListId,
        private readonly array $testPrices,
        private readonly array $packagePrices,
    ) {}

    /** No list at all, e.g. no default MRP list in effect. */
    public static function none(): self
    {
        return new self(null, [], []);
    }

    public function testPrice(string $testId): ?Money
    {
        return $this->testPrices[$testId] ?? null;
    }

    public function packagePrice(string $packageId): ?Money
    {
        return $this->packagePrices[$packageId] ?? null;
    }
}
