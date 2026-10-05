<?php

namespace App\Modules\Catalogue\Domain;

/** Why one requested item cannot be booked, with a stable code. */
final class QuoteProblem
{
    public const ITEM_NOT_FOUND = 'ITEM_NOT_FOUND';

    public const TEST_INACTIVE = 'TEST_INACTIVE';

    public const PACKAGE_INACTIVE = 'PACKAGE_INACTIVE';

    public const PRICE_MISSING = 'PRICE_MISSING';

    public const NO_ROUTE_FOR_TEST = 'NO_ROUTE_FOR_TEST';

    public const DUPLICATE_TEST = 'DUPLICATE_TEST';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly int $itemIndex,
        public readonly ?string $testId = null,
        public readonly ?string $packageId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toDetail(): array
    {
        return array_filter([
            'field' => "items.{$this->itemIndex}",
            'code' => $this->code,
            'issue' => $this->message,
            'test_id' => $this->testId,
            'package_id' => $this->packageId,
        ], fn ($value) => $value !== null);
    }
}
