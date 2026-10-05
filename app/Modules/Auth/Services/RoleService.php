<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Domain\RoleAssignmentPolicy;
use App\Modules\Auth\Errors\AuthError;
use App\Modules\Auth\Models\PermissionEntry;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\Permission;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Scoping\ScopeFilter;
use App\Modules\Shared\Scoping\ScopeLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Custom roles created by HQ (spec §4). System roles are seeded and
 * read-only. A role's scope level is fixed at creation, because changing it
 * would silently change what every holder can see.
 */
final class RoleService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name: string, scope_level: string, description?: string|null}  $attributes
     */
    public function create(string $organizationId, array $attributes): Role
    {
        $this->assertNameNotReserved($attributes['name']);

        return DB::transaction(function () use ($organizationId, $attributes): Role {
            $role = new Role($attributes);
            $role->organization_id = $organizationId;
            $role->is_system = false;
            $role->save();
            $this->auditLogger->recordCreated('role.create', $role);

            return $role->load('permissionEntries');
        });
    }

    /**
     * @param  array{name?: string, description?: string|null}  $changes
     */
    public function update(Role $role, array $changes): Role
    {
        $this->assertEditable($role);

        if (isset($changes['name'])) {
            $this->assertNameNotReserved($changes['name']);
        }

        return DB::transaction(function () use ($role, $changes): Role {
            $role->fill($changes)->save();
            $this->auditLogger->recordChanges('role.update', $role);

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        $this->assertEditable($role);

        // Staff the caller cannot see still hold the role, so look past scope.
        if (User::query()->withoutGlobalScope(ScopeFilter::class)->where('role_id', $role->id)->exists()) {
            throw AuthError::roleInUse();
        }

        DB::transaction(function () use ($role): void {
            $role->delete();
            $this->auditLogger->record('role.delete', $role);
        });
    }

    /**
     * Replaces the role's permissions. Every permission must suit the role's
     * scope level and be held by the person making the change.
     *
     * @param  list<string>  $permissionNames
     */
    public function replacePermissions(StaffContext $granter, Role $role, array $permissionNames): Role
    {
        $this->assertEditable($role);
        $permissions = $this->resolvePermissions($permissionNames);
        $this->assertFitScope($role->scope_level, $permissions);

        $notHeld = RoleAssignmentPolicy::permissionsNotHeld($granter->permissions(), $permissions);
        if ($notHeld !== []) {
            throw AuthError::roleNotAssignable('You cannot grant permissions you do not hold: '.implode(', ', array_map(fn (Permission $p) => $p->value, $notHeld)).'.');
        }

        return DB::transaction(function () use ($role, $permissions): Role {
            $before = array_map(fn (Permission $p) => $p->value, $role->permissions());
            $entryIds = PermissionEntry::query()->whereIn('name', array_map(fn (Permission $p) => $p->value, $permissions))->pluck('id');

            $role->permissionEntries()->sync($entryIds);
            $role->touch();
            $role->load('permissionEntries');

            $after = array_map(fn (Permission $p) => $p->value, $role->permissions());
            $this->auditLogger->record('role.permissions_change', $role, ['permissions' => $before], ['permissions' => $after]);

            return $role;
        });
    }

    private function assertEditable(Role $role): void
    {
        if ($role->is_system) {
            throw AuthError::systemRoleReadOnly();
        }
    }

    private function assertNameNotReserved(string $name): void
    {
        $reserved = array_map(fn (SystemRole $role) => mb_strtolower($role->value), SystemRole::cases());

        if (in_array(mb_strtolower($name), $reserved, true)) {
            throw ValidationException::withMessages(['name' => 'This name belongs to a system role.']);
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<Permission>
     */
    private function resolvePermissions(array $names): array
    {
        $unknown = Permission::unknownNames($names);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['permissions' => 'Unknown permissions: '.implode(', ', $unknown).'.']);
        }

        return array_values(array_map(fn (string $name) => Permission::from($name), array_unique($names)));
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function assertFitScope(ScopeLevel $scopeLevel, array $permissions): void
    {
        $aboveScope = array_filter($permissions, fn (Permission $permission): bool => ! $permission->isAllowedFor($scopeLevel));

        if ($aboveScope !== []) {
            throw AuthError::permissionsAboveScope(array_values(array_map(fn (Permission $p) => $p->value, $aboveScope)), $scopeLevel->value);
        }
    }
}
