<?php

namespace App\Modules\Network\Services;

/** Where a branch is on the map; home-collection routes start here. */
final class BranchLocation
{
    public function __construct(
        public readonly string $branchId,
        public readonly ?string $latitude,
        public readonly ?string $longitude,
    ) {}
}
