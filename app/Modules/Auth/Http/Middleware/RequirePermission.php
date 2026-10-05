<?php

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Permissions\Permission;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permission:manage_branches,view_branches` passes when the staff member
 * holds any one of the listed permissions. Missing permission is 403; rows
 * outside the caller's scope are handled separately and return 404.
 */
final class RequirePermission
{
    public function __construct(private readonly StaffContext $staff) {}

    public function handle(Request $request, Closure $next, string ...$permissionNames): Response
    {
        foreach ($permissionNames as $name) {
            if ($this->staff->has(Permission::from($name))) {
                return $next($request);
            }
        }

        throw new DomainError(ErrorCode::FORBIDDEN, 'You do not have permission to perform this action.', 403);
    }
}
