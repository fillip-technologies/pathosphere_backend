<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\Permission;
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

    /** An active staff member based at the branch who holds the permission, e.g. a phlebotomist. */
    public function isActiveStaffWithPermissionAt(string $userId, string $branchId, Permission $permission): bool
    {
        $user = User::query()->with('role.permissionEntries')->whereKey($userId)->where('branch_id', $branchId)->where('status', UserStatus::Active)->first();

        return $user !== null && in_array($permission, $user->role->permissions(), true);
    }

    public function hasStaffInRegion(string $regionId): bool
    {
        return User::query()->withoutGlobalScope(ScopeFilter::class)->where('region_id', $regionId)->exists();
    }
}
