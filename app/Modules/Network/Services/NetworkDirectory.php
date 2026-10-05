<?php

namespace App\Modules\Network\Services;

use App\Modules\Network\Models\B2bClient;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\Region;

/**
 * Read-only questions other modules ask about the network. Every query runs
 * under the caller's current scope, so "exists" means "exists and is visible
 * to you".
 */
final class NetworkDirectory
{
    /**
     * The region and all regions below it.
     *
     * @return list<string>
     */
    public function regionTreeIds(string $regionId): array
    {
        $treeIds = [$regionId];
        $frontier = [$regionId];

        while ($frontier !== []) {
            $frontier = Region::query()->whereIn('parent_region_id', $frontier)->pluck('id')->all();
            $treeIds = [...$treeIds, ...$frontier];
        }

        return $treeIds;
    }

    /**
     * @param  list<string>  $regionIds
     * @return list<string>
     */
    public function franchiseIdsInRegions(array $regionIds): array
    {
        return Franchise::query()->whereIn('region_id', $regionIds)->pluck('id')->all();
    }

    /**
     * @param  list<string>  $regionIds
     * @return list<string>
     */
    public function branchIdsInRegions(array $regionIds): array
    {
        return Branch::query()->whereIn('region_id', $regionIds)->pluck('id')->all();
    }

    /** @return list<string> */
    public function branchIdsOfFranchise(string $franchiseId): array
    {
        return Branch::query()->where('franchise_id', $franchiseId)->pluck('id')->all();
    }

    public function regionIsVisible(string $regionId): bool
    {
        return Region::query()->whereKey($regionId)->exists();
    }

    public function franchiseIsVisible(string $franchiseId): bool
    {
        return Franchise::query()->whereKey($franchiseId)->exists();
    }

    public function branchIsVisible(string $branchId): bool
    {
        return Branch::query()->whereKey($branchId)->exists();
    }

    public function b2bClientIsVisible(string $b2bClientId): bool
    {
        return B2bClient::query()->whereKey($b2bClientId)->exists();
    }
}
