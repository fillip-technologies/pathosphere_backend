<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\PermissionEntry;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Permissions\Permission;
use App\Modules\Auth\Permissions\SystemRole;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the `permissions` table and the system roles in step with the code.
 * Safe to run on every deploy: it only adds and updates.
 */
final class PermissionCatalogue
{
    public function sync(): void
    {
        DB::transaction(function (): void {
            $this->syncPermissions();
            $this->syncSystemRoles();
        });
    }

    private function syncPermissions(): void
    {
        foreach (Permission::cases() as $permission) {
            PermissionEntry::query()->updateOrCreate(
                ['name' => $permission->value],
                ['module' => $permission->module(), 'description' => $permission->description()],
            );
        }
    }

    private function syncSystemRoles(): void
    {
        $entryIds = PermissionEntry::query()->pluck('id', 'name');

        foreach (SystemRole::cases() as $systemRole) {
            $role = Role::query()->firstOrNew(['organization_id' => null, 'name' => $systemRole->value]);
            $role->scope_level = $systemRole->scopeLevel();
            $role->is_system = true;
            $role->description = $systemRole->description();
            $role->save();

            $role->permissionEntries()->sync(
                array_map(fn (Permission $permission) => $entryIds[$permission->value], $systemRole->permissions()),
            );
        }
    }
}
