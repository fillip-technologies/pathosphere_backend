<?php

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Permissions\Permission;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\ScopeLevel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named set of permissions at one scope level (spec §7.2). System roles
 * (organization_id null, is_system true) are shared and read-only.
 *
 * Not network-scoped: roles are visible to the whole organization and
 * guarded by the manage_roles permission instead.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string $name
 * @property ScopeLevel $scope_level
 * @property bool $is_system
 * @property string|null $description
 * @property CarbonImmutable $updated_at
 * @property Collection<int, PermissionEntry> $permissionEntries
 */
final class Role extends BaseModel
{
    use SoftDeletes;

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['is_system' => false];

    protected function casts(): array
    {
        return [
            'scope_level' => ScopeLevel::class,
            'is_system' => 'boolean',
        ];
    }

    /** @return BelongsToMany<PermissionEntry, $this, RolePermission> */
    public function permissionEntries(): BelongsToMany
    {
        return $this->belongsToMany(PermissionEntry::class, 'role_permissions', 'role_id', 'permission_id')
            ->withTimestamps()
            ->using(RolePermission::class);
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return $this->permissionEntries
            ->map(fn (PermissionEntry $entry): Permission => $entry->permission())
            ->values()
            ->all();
    }

    public function requiresMfa(): bool
    {
        foreach ($this->permissions() as $permission) {
            if ($permission->requiresMfa()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Roles usable in an organization: its own plus the shared system roles.
     *
     * @param  Builder<Role>  $query
     */
    public function scopeAvailableTo(Builder $query, string $organizationId): void
    {
        $query->where(fn (Builder $inner) => $inner
            ->where('organization_id', $organizationId)
            ->orWhereNull('organization_id'));
    }
}
