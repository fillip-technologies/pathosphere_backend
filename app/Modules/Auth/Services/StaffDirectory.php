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

    /**
     * Active staff based at the branch whose role holds the permission, e.g.
     * the phlebotomists a day's home visits can go to.
     *
     * @return list<string> user IDs
     */
    public function activeStaffWithPermissionAt(string $branchId, Permission $permission): array
    {
        return User::query()
            ->with('role.permissionEntries')
            ->where('branch_id', $branchId)
            ->where('status', UserStatus::Active)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => in_array($permission, $user->role->permissions(), true))
            ->pluck('id')
            ->values()
            ->all();
    }

    /** The branch a staff member the caller can see is based at; null when not visible or not branch-based. */
    public function visibleStaffBranchId(string $userId): ?string
    {
        return User::query()->whereKey($userId)->value('branch_id');
    }

    public function hasStaffInRegion(string $regionId): bool
    {
        return User::query()->withoutGlobalScope(ScopeFilter::class)->where('region_id', $regionId)->exists();
    }

    /** An active staff member of the organization whose role holds the permission, wherever they are based. */
    public function isActiveStaffWithPermission(string $organizationId, string $userId, Permission $permission): bool
    {
        $user = User::query()->with('role.permissionEntries')->whereKey($userId)->where('organization_id', $organizationId)->where('status', UserStatus::Active)->first();

        return $user !== null && in_array($permission, $user->role->permissions(), true);
    }

    /**
     * Names printed on reports and shown in lists, e.g. who verified a result.
     *
     * @param  list<string>  $userIds
     * @return array<string, string> by user ID
     */
    public function names(array $userIds): array
    {
        return User::query()->withoutGlobalScope(ScopeFilter::class)->withTrashed()->whereKey(array_values(array_unique($userIds)))->pluck('name', 'id')->all();
    }
}
