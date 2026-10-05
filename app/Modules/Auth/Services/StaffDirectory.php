<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Scoping\ScopeFilter;

/**
 * Questions other modules ask about staff, e.g. before deleting a branch.
 * These integrity checks look past the caller's scope on purpose: a branch
 * with staff the caller cannot see is still in use.
 */
final class StaffDirectory
{
    public function hasStaffAtBranch(string $branchId): bool
    {
        return User::query()->withoutGlobalScope(ScopeFilter::class)->where('branch_id', $branchId)->exists();
    }

    public function hasStaffInRegion(string $regionId): bool
    {
        return User::query()->withoutGlobalScope(ScopeFilter::class)->where('region_id', $regionId)->exists();
    }
}
