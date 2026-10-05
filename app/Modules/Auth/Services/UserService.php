<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Domain\RoleAssignmentPolicy;
use App\Modules\Auth\Domain\StaffPlacement;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Auth\Errors\AuthError;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\StateMachines\UserStateMachine;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and changes staff. Every change of role or placement is checked
 * against the granter: the role must fit inside their scope, and the
 * branch/region/franchise/client must be one they can see (spec §4, §8).
 */
final class UserService
{
    private const PLACEMENT_FIELDS = ['region_id', 'franchise_id', 'branch_id', 'b2b_client_id'];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NetworkDirectory $network,
        private readonly UserStateMachine $stateMachine,
        private readonly TokenIssuer $tokenIssuer,
        private readonly StaffAccounts $accounts,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated user fields plus `password`
     */
    public function create(StaffContext $granter, array $attributes): User
    {
        $role = $this->findAssignableRole($granter, $attributes['role_id']);
        $placement = $this->placementFor($role, $attributes);

        return DB::transaction(function () use ($granter, $attributes, $role, $placement): User {
            $user = new User([
                'role_id' => $role->id,
                'employee_code' => $attributes['employee_code'] ?? null,
                'name' => $attributes['name'],
                'email' => $this->normalisedEmail($attributes['email'] ?? null),
                'phone' => $attributes['phone'],
                ...$placement->toColumns(),
            ]);
            $user->organization_id = $granter->user()->organization_id;
            $user->status = UserStatus::Active;
            $user->save();

            $this->accounts->create($user, $attributes['password']);

            $this->auditLogger->recordCreated('user.create', $user);

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $changes  validated fields
     */
    public function update(StaffContext $granter, User $user, array $changes): User
    {
        $changesAccess = array_key_exists('role_id', $changes) || array_intersect_key($changes, array_flip(self::PLACEMENT_FIELDS)) !== [];

        if ($changesAccess) {
            $this->assertNotSelf($granter, $user);
            $role = $this->findAssignableRole($granter, $changes['role_id'] ?? $user->role_id);
            $currentPlacement = $user->only(self::PLACEMENT_FIELDS);
            $changes = [...$changes, 'role_id' => $role->id, ...$this->placementFor($role, $changes + $currentPlacement)->toColumns()];
        }

        if (array_key_exists('email', $changes)) {
            $changes['email'] = $this->normalisedEmail($changes['email']);
        }

        return DB::transaction(function () use ($user, $changes): User {
            $user->fill($changes)->save();
            $this->accounts->syncLoginIdentifier($user);
            $this->auditLogger->recordChanges('user.update', $user);

            return $user;
        });
    }

    public function disable(StaffContext $granter, User $user): User
    {
        $this->assertNotSelf($granter, $user);

        return DB::transaction(function () use ($user): User {
            $this->stateMachine->transition($user, UserStatus::Disabled);
            $this->tokenIssuer->revokeAllFor($this->accounts->for($user));

            return $user;
        });
    }

    public function enable(User $user): User
    {
        $this->stateMachine->transition($user, UserStatus::Active);

        return $user;
    }

    public function delete(StaffContext $granter, User $user): void
    {
        $this->assertNotSelf($granter, $user);

        DB::transaction(function () use ($user): void {
            $account = $this->accounts->for($user);
            $this->tokenIssuer->revokeAllFor($account);
            $account->update(['is_active' => false]);
            $user->delete();
            $this->auditLogger->record('user.delete', $user);
        });
    }

    private function findAssignableRole(StaffContext $granter, string $roleId): Role
    {
        $role = Role::query()
            ->availableTo($granter->user()->organization_id)
            ->with('permissionEntries')
            ->find($roleId);

        if ($role === null) {
            throw ValidationException::withMessages(['role_id' => 'The selected role does not exist.']);
        }

        $refusal = RoleAssignmentPolicy::assignmentRefusal($granter->role()->scope_level, $role->scope_level);

        return $refusal === null ? $role : throw AuthError::roleNotAssignable($refusal);
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function placementFor(Role $role, array $columns): StaffPlacement
    {
        $placement = StaffPlacement::forRole($role->scope_level, array_intersect_key($columns, array_flip(self::PLACEMENT_FIELDS)));

        if (is_array($placement)) {
            throw ValidationException::withMessages([$placement['field'] => $placement['issue']]);
        }

        $visible = match (true) {
            $placement->regionId !== null => $this->network->regionIsVisible($placement->regionId),
            $placement->franchiseId !== null => $this->network->franchiseIsVisible($placement->franchiseId),
            $placement->branchId !== null => $this->network->branchIsVisible($placement->branchId),
            $placement->b2bClientId !== null => $this->network->b2bClientIsVisible($placement->b2bClientId),
            default => true,
        };

        if (! $visible) {
            $field = StaffPlacement::requiredColumn($role->scope_level) ?? 'role_id';

            throw ValidationException::withMessages([$field => 'The selected location does not exist.']);
        }

        return $placement;
    }

    private function assertNotSelf(StaffContext $granter, User $user): void
    {
        if ($granter->user()->is($user)) {
            throw AuthError::cannotChangeOwnAccess();
        }
    }

    private function normalisedEmail(?string $email): ?string
    {
        return $email === null ? null : mb_strtolower(trim($email));
    }
}
