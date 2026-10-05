<?php

namespace App\Modules\Auth\Domain;

use App\Modules\Auth\Permissions\Permission;
use App\Modules\Shared\Scoping\ScopeLevel;

/**
 * Who may grant what (spec §4, §8).
 *
 * Assigning a role: the role's scope must sit inside the granter's scope. A
 * branch admin hires front desk staff without holding create_order
 * themselves, but can never create regional or head-office staff.
 *
 * Editing a role's permissions: the editor must also hold every permission
 * they grant, so nobody can build a role more powerful than their own.
 */
final class RoleAssignmentPolicy
{
    public static function assignmentRefusal(ScopeLevel $granterScope, ScopeLevel $roleScope): ?string
    {
        if (in_array($roleScope, self::assignableScopes($granterScope), true)) {
            return null;
        }

        return "A {$granterScope->value}-level user cannot assign a {$roleScope->value}-level role.";
    }

    /**
     * @param  list<Permission>  $editorPermissions
     * @param  list<Permission>  $grantedPermissions
     * @return list<Permission> granted permissions the editor does not hold
     */
    public static function permissionsNotHeld(array $editorPermissions, array $grantedPermissions): array
    {
        return array_values(array_filter(
            $grantedPermissions,
            fn (Permission $permission): bool => ! in_array($permission, $editorPermissions, true),
        ));
    }

    /** @return list<ScopeLevel> */
    public static function assignableScopes(ScopeLevel $granterScope): array
    {
        return match ($granterScope) {
            ScopeLevel::Organization => ScopeLevel::cases(),
            ScopeLevel::Region => [ScopeLevel::Region, ScopeLevel::Franchise, ScopeLevel::Branch, ScopeLevel::B2bClient],
            ScopeLevel::Franchise => [ScopeLevel::Franchise, ScopeLevel::Branch],
            ScopeLevel::Branch => [ScopeLevel::Branch],
            ScopeLevel::B2bClient => [],
        };
    }
}
