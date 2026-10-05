<?php

namespace App\Modules\Network\Services;

/** How to reach a branch with an operational alert. */
final class BranchContact
{
    public function __construct(
        public readonly string $branchId,
        public readonly string $organizationId,
        public readonly string $branchCode,
        public readonly string $name,
        public readonly string $phone,
    ) {}
}
