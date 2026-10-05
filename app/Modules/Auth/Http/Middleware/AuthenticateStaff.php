<?php

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Enums\AccountOwnerType;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Auth\Errors\AuthError;
use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\ScopeResolver;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Auth\Services\TokenIssuer;
use App\Modules\Shared\Context\Actor;
use App\Modules\Shared\Context\CurrentActor;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `auth:sanctum` on every staff endpoint. Loads the user, role and
 * permissions once, resolves their scope (spec §4.1) and makes all three
 * available to the rest of the request.
 */
final class AuthenticateStaff
{
    public function __construct(
        private readonly StaffContext $staff,
        private readonly CurrentScope $currentScope,
        private readonly CurrentActor $currentActor,
        private readonly ScopeResolver $scopeResolver,
        private readonly TokenIssuer $tokenIssuer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if (! $account instanceof Account || $account->owner_type !== AccountOwnerType::User) {
            throw AuthError::notStaff();
        }

        $user = $this->currentScope->runAs(
            ScopeContext::system(),
            fn (): ?User => User::query()->with('role.permissionEntries')->find($account->owner_id),
        );

        if (! $account->is_active || $user === null || $user->status !== UserStatus::Active) {
            throw AuthError::accountDisabled();
        }

        $session = $this->tokenIssuer->sessionForAccessToken($account->currentAccessToken());

        $this->staff->set($user, $user->role->permissions(), $session);
        $this->currentScope->set($this->scopeResolver->forUser($user));
        $this->currentActor->set(Actor::user($user->id, $user->organization_id));

        // Added to every log line for this request (spec §11 observability).
        Context::add(['user_id' => $user->id, 'branch_id' => $user->branch_id]);

        return $next($request);
    }
}
