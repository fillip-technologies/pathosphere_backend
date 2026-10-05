<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Enums\AccountOwnerType;
use App\Modules\Auth\Enums\AuthMethod;
use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Hash;

/** The password login behind a staff member. */
final class StaffAccounts
{
    public function create(User $user, string $password): Account
    {
        $account = new Account([
            'owner_type' => AccountOwnerType::User,
            'owner_id' => $user->id,
            'login_identifier' => $this->loginIdentifierFor($user),
            'auth_method' => AuthMethod::Password,
        ]);
        $account->password_hash = Hash::make($password);
        $account->save();

        return $account;
    }

    public function for(User $user): Account
    {
        return Account::query()
            ->where('owner_type', AccountOwnerType::User)
            ->where('owner_id', $user->id)
            ->firstOrFail();
    }

    /** Keeps the login identifier in step after an email or phone change. */
    public function syncLoginIdentifier(User $user): void
    {
        $this->for($user)->update(['login_identifier' => $this->loginIdentifierFor($user)]);
    }

    /** Staff sign in with their email, or their phone when they have no email. */
    private function loginIdentifierFor(User $user): string
    {
        return $user->email ?? $user->phone;
    }
}
