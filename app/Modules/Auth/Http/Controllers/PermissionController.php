<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Permissions\Permission;
use App\Modules\Shared\Scoping\ScopeLevel;
use Illuminate\Http\JsonResponse;

/**
 * GET /permissions: the fixed catalogue, for role editors. It is small and
 * static, so it is returned whole; the pagination envelope keeps the list
 * shape identical to every other list endpoint.
 */
final class PermissionController
{
    public function __invoke(): JsonResponse
    {
        $permissions = array_map(fn (Permission $permission): array => [
            'name' => $permission->value,
            'module' => $permission->module(),
            'description' => $permission->description(),
            'allowed_scope_levels' => array_map(fn (ScopeLevel $level) => $level->value, $permission->allowedScopeLevels()),
            'requires_mfa' => $permission->requiresMfa(),
        ], Permission::cases());

        return new JsonResponse([
            'data' => $permissions,
            'pagination' => ['next_cursor' => null, 'limit' => count($permissions)],
        ]);
    }
}
