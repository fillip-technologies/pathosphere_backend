<?php

namespace App\Modules\Network\Services;

use App\Modules\Network\Enums\BranchStatus;

/** What pricing and routing need to know about a booking branch. */
final class BranchPricingProfile
{
    public function __construct(
        public readonly string $branchId,
        public readonly string $organizationId,
        public readonly BranchStatus $status,
        public readonly ?string $mrpPriceListId,
        public readonly ?string $franchiseId,
        /** Set for franchise branches whose franchise has a partner price list. */
        public readonly ?string $partnerPriceListId,
    ) {}

    public function isOperating(): bool
    {
        return $this->status === BranchStatus::Active;
    }
}
