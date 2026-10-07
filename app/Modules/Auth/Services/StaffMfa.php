<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Domain\Totp;
use App\Modules\Auth\Errors\AuthError;
use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Models\User;
use App\Modules\Shared\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Optional MFA that a signed-in staff member switches on and off
 * (spec §10.4). Set-up keeps the new secret, encrypted, in the cache until
 * the first code from the authenticator app proves it was saved.
 */
final class StaffMfa
{
    public function __construct(
        private readonly StaffAccounts $accounts,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @return array{secret: string, otpauth_uri: string} */
    public function start(User $user): array
    {
        $account = $this->accounts->for($user);

        if ($account->mfa_enabled) {
            throw AuthError::mfaAlreadyEnabled();
        }

        $secret = Totp::generateSecret();
        Cache::put(
            $this->pendingKey($account),
            Crypt::encryptString($secret),
            now()->addMinutes((int) config('pathology.auth.mfa_challenge_minutes')),
        );

        return [
            'secret' => $secret,
            'otpauth_uri' => Totp::provisioningUri($secret, $account->login_identifier, (string) config('pathology.auth.mfa_issuer')),
        ];
    }

    /** Switches MFA on once a code from the new secret checks out. */
    public function confirm(User $user, string $code): void
    {
        $account = $this->accounts->for($user);

        if ($account->mfa_enabled) {
            throw AuthError::mfaAlreadyEnabled();
        }

        $stored = Cache::get($this->pendingKey($account));

        if (! is_string($stored)) {
            throw AuthError::mfaEnrollmentNotStarted();
        }

        $secret = Crypt::decryptString($stored);

        if (! Totp::verify($secret, $code, CarbonImmutable::now()->getTimestamp())) {
            throw AuthError::invalidMfaCode();
        }

        Cache::forget($this->pendingKey($account));
        $account->forceFill(['mfa_secret' => $secret, 'mfa_enabled' => true])->save();
        $this->auditLogger->record('user.mfa_enable', $user, ['mfa_enabled' => false], ['mfa_enabled' => true]);
    }

    /** Switches MFA off; a current code proves the authenticator is at hand. */
    public function disable(User $user, string $code): void
    {
        $account = $this->accounts->for($user);

        if (! $account->mfa_enabled || $account->mfa_secret === null) {
            throw AuthError::mfaNotEnabled();
        }

        if (! Totp::verify($account->mfa_secret, $code, CarbonImmutable::now()->getTimestamp())) {
            throw AuthError::invalidMfaCode();
        }

        $account->forceFill(['mfa_secret' => null, 'mfa_enabled' => false])->save();
        $this->auditLogger->record('user.mfa_disable', $user, ['mfa_enabled' => true], ['mfa_enabled' => false]);
    }

    public function isEnabled(User $user): bool
    {
        return $this->accounts->for($user)->mfa_enabled;
    }

    private function pendingKey(Account $account): string
    {
        return 'mfa_setup:'.$account->id;
    }
}
