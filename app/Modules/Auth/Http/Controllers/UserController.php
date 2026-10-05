<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\UserRequest;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Auth\Services\UserService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Staff management. Scope decides whose staff the caller can see and change. */
final class UserController
{
    public function __construct(
        private readonly UserService $users,
        private readonly StaffContext $staff,
    ) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'status' => 'status',
                'role_id' => 'role_id',
                'region_id' => 'region_id',
                'franchise_id' => 'franchise_id',
                'branch_id' => 'branch_id',
                'b2b_client_id' => 'b2b_client_id',
            ])
            ->allowSorts(['name', 'created_at'])
            ->allowSearch(fn ($query, string $text) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$text}%")
                ->orWhere('phone', $text)
                ->orWhere('email', $text)
                ->orWhere('employee_code', $text)))
            ->apply(User::query()->with('role'));

        return CursorPage::respond($query, $request, UserResource::class);
    }

    public function store(UserRequest $request): Response
    {
        $user = $this->users->create($this->staff, $request->validated());

        return ApiResponse::created(UserResource::make($user->load('role')), "/api/v1/users/{$user->id}");
    }

    public function show(User $user): Response
    {
        return EntityTag::attach(UserResource::make($user->load('role'))->response(), $user);
    }

    public function update(UserRequest $request, User $user): Response
    {
        EntityTag::assertIfMatch($request, $user);
        $user = $this->users->update($this->staff, $user, $request->validated());

        return EntityTag::attach(UserResource::make($user->load('role'))->response(), $user);
    }

    public function destroy(User $user): Response
    {
        $this->users->delete($this->staff, $user);

        return ApiResponse::noContent();
    }

    public function disable(User $user): Response
    {
        $user = $this->users->disable($this->staff, $user);

        return EntityTag::attach(UserResource::make($user->load('role'))->response(), $user);
    }

    public function enable(User $user): Response
    {
        $user = $this->users->enable($user);

        return EntityTag::attach(UserResource::make($user->load('role'))->response(), $user);
    }
}
