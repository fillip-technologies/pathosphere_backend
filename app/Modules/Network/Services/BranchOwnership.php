<?php

namespace App\Modules\Network\Services;

/** Where a branch sits: its region, and who owns it (HQ when there is no franchise). */
final class BranchOwnership
{
    public function __construct(
        public readonly string $branchId,
        public readonly string $branchCode,
        public readonly string $name,
        public readonly ?string $franchiseId,
        public readonly string $regionId,
    ) {}

    public function isCompanyOwned(): bool
    {
        return $this->franchiseId === null;
    }
}
