<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Enums\AccountOwnerType;
use App\Modules\Auth\Enums\AuthMethod;
use App\Modules\Auth\Errors\AuthError;
use App\Modules\Auth\Models\Account;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Login identities of patients and doctors (spec §7.9): one account per
 * phone and owner type, signed in by OTP. Which patient or doctor owns a
 * phone is decided by the module that knows them; this class only keeps
 * the account and issues its tokens.
 */
final class PersonAccounts
{
    public function __construct(private readonly TokenIssuer $tokenIssuer) {}

    /** The account behind a phone, if one was ever created. */
    public function findByPhone(AccountOwnerType $ownerType, string $phone): ?PersonAccount
    {
        $account = Account::query()
            ->where('owner_type', $ownerType)
            ->where('login_identifier', $phone)
            ->first();

        return $account === null ? null : $this->present($account);
    }

    /** Finds the phone's account or opens one owned by the given person. */
    public function findOrCreate(AccountOwnerType $ownerType, string $phone, string $ownerId): PersonAccount
    {
        $existing = $this->findByPhone($ownerType, $phone);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $account = new Account;
            $account->forceFill([
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'login_identifier' => $phone,
                'auth_method' => AuthMethod::Otp,
            ])->save();

            return $this->present($account);
        } catch (UniqueConstraintViolationException) {
            // A parallel sign-in opened it first.
            return $this->findByPhone($ownerType, $phone) ?? throw AuthError::invalidCredentials();
        }
    }

    /** A person whose record moved (e.g. a merged patient) keeps the same login. */
    public function moveTo(PersonAccount $account, string $ownerId): void
    {
        Account::query()->whereKey($account->id)->update(['owner_id' => $ownerId]);
    }

    public function signIn(PersonAccount $account, ?string $ipAddress, ?string $deviceInfo): IssuedTokens
    {
        $model = Account::query()->findOrFail($account->id);

        if (! $model->is_active) {
            throw AuthError::accountDisabled();
        }

        $model->forceFill(['last_login_at' => now()])->save();

        return $this->tokenIssuer->startSession($model, $ipAddress, $deviceInfo);
    }

    private function present(Account $account): PersonAccount
    {
        return new PersonAccount($account->id, $account->owner_type, $account->owner_id, $account->login_identifier, $account->is_active);
    }
}
