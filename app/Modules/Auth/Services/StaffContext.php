<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\AuthSession;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\Permission;
use LogicException;

/**
 * The signed-in staff member for this request: user, role and permissions,
 * loaded once by the AuthenticateStaff middleware.
 */
final class StaffContext
{
    private ?User $user = null;

    private ?AuthSession $session = null;

    /** @var list<Permission> */
    private array $permissions = [];

    /** @param  list<Permission>  $permissions */
    public function set(User $user, array $permissions, ?AuthSession $session): void
    {
        $this->user = $user;
        $this->permissions = $permissions;
        $this->session = $session;
    }

    public function user(): User
    {
        return $this->user ?? throw new LogicException('No staff member is signed in.');
    }

    public function role(): Role
    {
        return $this->user()->role;
    }

    public function session(): ?AuthSession
    {
        return $this->session;
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function has(Permission $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function isSignedIn(): bool
    {
        return $this->user !== null;
    }
}
