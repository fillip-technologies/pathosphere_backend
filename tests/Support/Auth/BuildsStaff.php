<?php

namespace Tests\Support\Auth;

use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Auth\Services\PermissionCatalogue;
use App\Modules\Auth\Services\StaffAccounts;
use App\Modules\Auth\Services\TokenIssuer;
use App\Modules\Network\Models\Organization;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;

/**
 * Builds an organization, roles and staff with real accounts and real bearer
 * tokens, so feature tests exercise the same middleware as production.
 */
trait BuildsStaff
{
    protected const STAFF_PASSWORD = 'Correct-horse-42-battery';

    protected Organization $organization;

    protected function setUpOrganization(): void
    {
        $this->asSystem(function (): void {
            app(PermissionCatalogue::class)->sync();
            $this->organization = Organization::factory()->create();
        });
    }

    /** For tests that run the full seeders: use the seeded organization. */
    protected function useSeededOrganization(): void
    {
        $this->organization = $this->asSystem(fn () => Organization::query()->firstOrFail());
    }

    /**
     * @param  array<string, string|null>  $placement  region_id / franchise_id / branch_id / b2b_client_id
     * @param  array<string, mixed>  $attributes
     */
    protected function staff(SystemRole|Role $role, array $placement = [], array $attributes = []): User
    {
        return $this->asSystem(function () use ($role, $placement, $attributes): User {
            $roleModel = $role instanceof Role
                ? $role
                : Role::query()->whereNull('organization_id')->where('name', $role->value)->firstOrFail();

            $user = User::factory()->create([
                'organization_id' => $this->organization->id,
                'role_id' => $roleModel->id,
                'status' => UserStatus::Active,
                ...$placement,
                ...$attributes,
            ]);
            app(StaffAccounts::class)->create($user, self::STAFF_PASSWORD);

            return $user;
        });
    }

    /** A real access token, as if the user had signed in (MFA already done). */
    protected function tokenFor(User $user): string
    {
        return $this->asSystem(fn () => app(TokenIssuer::class)
            ->startSession(app(StaffAccounts::class)->for($user), '127.0.0.1', 'phpunit')
            ->accessToken);
    }

    protected function actingAsStaff(User $user): static
    {
        // Guards cache the resolved user between requests in one test.
        app('auth')->forgetGuards();

        return $this->withToken($this->tokenFor($user));
    }

    /**
     * Runs test setup or assertions that read scoped tables, outside any request.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function asSystem(callable $callback): mixed
    {
        return app(CurrentScope::class)->runAs(ScopeContext::system(), $callback);
    }
}
