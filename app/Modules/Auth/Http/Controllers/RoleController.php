<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\RolePermissionsRequest;
use App\Modules\Auth\Http\Requests\RoleRequest;
use App\Modules\Auth\Http\Resources\RoleResource;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Services\RoleService;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** System roles (read-only) and the organization's custom roles. */
final class RoleController
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly StaffContext $staff,
    ) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters(['scope_level' => 'scope_level', 'is_system' => 'is_system'])
            ->allowSorts(['name', 'created_at'])
            ->apply(Role::query()->availableTo($this->staff->user()->organization_id)->with('permissionEntries'));

        return CursorPage::respond($query, $request, RoleResource::class);
    }

    public function store(RoleRequest $request): Response
    {
        $role = $this->roles->create($this->staff->user()->organization_id, $request->validated());

        return ApiResponse::created(RoleResource::make($role), "/api/v1/roles/{$role->id}");
    }

    public function show(Role $role): Response
    {
        return EntityTag::attach(RoleResource::make($role)->response(), $role);
    }

    public function update(RoleRequest $request, Role $role): Response
    {
        EntityTag::assertIfMatch($request, $role);
        $role = $this->roles->update($role, $request->validated());

        return EntityTag::attach(RoleResource::make($role)->response(), $role);
    }

    public function destroy(Role $role): Response
    {
        $this->roles->delete($role);

        return ApiResponse::noContent();
    }

    public function replacePermissions(RolePermissionsRequest $request, Role $role): Response
    {
        EntityTag::assertIfMatch($request, $role);
        $role = $this->roles->replacePermissions($this->staff, $role, $request->permissionNames());

        return EntityTag::attach(RoleResource::make($role)->response(), $role);
    }
}
