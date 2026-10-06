<?php

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Enums\AccountOwnerType;
use App\Modules\Auth\Errors\AuthError;
use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Services\PersonAccount;
use App\Modules\Auth\Services\SignedInPerson;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `auth:sanctum` on patient and doctor endpoints (spec §4: their
 * own guards, no staff role): `person:patient` or `person:doctor`.
 *
 * No network scope is set for these requests. Their data is reached through
 * the person's own records only, and any accidental query on a scoped table
 * fails closed (MissingScope) instead of seeing the network.
 */
final class AuthenticatePerson
{
    public function __construct(private readonly SignedInPerson $person) {}

    public function handle(Request $request, Closure $next, string $ownerType): Response
    {
        $expected = AccountOwnerType::from($ownerType);
        $account = $request->user();

        if (! $account instanceof Account || $account->owner_type !== $expected) {
            throw AuthError::wrongAccountType($expected->value);
        }

        if (! $account->is_active) {
            throw AuthError::accountDisabled();
        }

        $this->person->set(new PersonAccount($account->id, $account->owner_type, $account->owner_id, $account->login_identifier, $account->is_active));
        Context::add(['account_id' => $account->id]);

        return $next($request);
    }
}
