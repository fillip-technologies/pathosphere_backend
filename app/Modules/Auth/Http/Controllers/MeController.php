<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Auth\Permissions\Permission;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Shared\Scoping\CurrentScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /me: who is signed in, what they may do and what they can see. */
final class MeController
{
    public function __invoke(Request $request, StaffContext $staff, CurrentScope $currentScope): JsonResponse
    {
        // Set by AuthenticateStaff on every staff route.
        $scope = $currentScope->get() ?? throw new \LogicException('No scope resolved for this request.');

        return new JsonResponse(['data' => [
            'user' => UserResource::make($staff->user())->resolve($request),
            'permissions' => array_map(fn (Permission $permission) => $permission->value, $staff->permissions()),
            'scope' => [
                'level' => $scope->level,
                'organization_id' => $scope->organizationId,
                'region_ids' => $scope->regionIds,
                'franchise_ids' => $scope->franchiseIds,
                'branch_ids' => $scope->branchIds,
                'b2b_client_id' => $scope->b2bClientId,
            ],
        ]]);
    }
}
