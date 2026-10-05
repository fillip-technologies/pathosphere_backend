<?php

namespace App\Modules\Catalogue\Domain;

use App\Modules\Shared\Money\Money;

/**
 * A priced line. Test lines carry their processing lab; a package line carries
 * the package price and its child test lines at price 0 (spec §7.5).
 */
final class QuoteLine
{
    public const TEST = 'test';

    public const PACKAGE = 'package';

    /** @param  list<QuoteLine>  $children */
    public function __construct(
        public readonly string $lineType,
        public readonly string $itemId,
        public readonly string $code,
        public readonly string $name,
        public readonly Money $mrpPrice,
        public readonly Money $partnerPrice,
        public readonly ?string $processingBranchId = null,
        public readonly ?int $tatHours = null,
        public readonly ?string $sampleType = null,
        public readonly ?string $containerType = null,
        public readonly array $children = [],
    ) {}
}
