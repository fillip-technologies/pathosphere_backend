<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Domain\Totp;
use App\Modules\Auth\Enums\AccountOwnerType;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Auth\Errors\AuthError;
use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Models\User;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

/**
 * Staff sign-in by password, with MFA where the role demands it
 * (spec §8.1, §10.3–10.4):
 *
 *   password ok, MFA not needed      → tokens
 *   password ok, MFA set up          → challenge → code → tokens
 *   password ok, MFA needed, not set → challenge → enrolment → code → tokens
 */
final class StaffSignIn
{
    public function __construct(
        private readonly TokenIssuer $tokenIssuer,
        private readonly MfaChallengeStore $challenges,
        private readonly CurrentScope $currentScope,
    ) {}

    public function withPassword(string $loginIdentifier, string $password, ?string $ipAddress, ?string $deviceInfo): SignInResult
    {
        $account = Account::query()
            ->where('owner_type', AccountOwnerType::User)
            ->where('login_identifier', mb_strtolower(trim($loginIdentifier)))
            ->first();

        if ($account === null) {
            // Spend the same time as a real check so response timing does not reveal accounts.
            Hash::check($password, Hash::make('timing-equaliser'));

            throw AuthError::invalidCredentials();
        }

        if ($account->isLocked()) {
            throw AuthError::accountLocked();
        }

        if ($account->password_hash === null || ! Hash::check($password, $account->password_hash)) {
            $this->recordFailedAttempt($account);

            throw AuthError::invalidCredentials();
        }

        $user = $this->activeUserFor($account);
        $account->forceFill(['failed_attempts' => 0, 'locked_until' => null])->save();

        if ($account->mfa_enabled || $user->role->requiresMfa()) {
            $challenge = new MfaChallenge($account->id, ! $account->mfa_enabled, null, 0, $ipAddress, $deviceInfo);
            $token = $this->challenges->create($challenge);

            return $challenge->isEnrollment
                ? SignInResult::mfaEnrollmentRequired($token)
                : SignInResult::mfaRequired($token);
        }

        return SignInResult::authenticated($this->completeSignIn($account, $ipAddress, $deviceInfo));
    }

    /**
     * Starts MFA enrolment for a challenge that requires it and returns the
     * new secret for the authenticator app.
     *
     * @return array{secret: string, otpauth_uri: string}
     */
    public function startMfaEnrollment(string $challengeToken): array
    {
        $challenge = $this->challenges->get($challengeToken);

        if (! $challenge->isEnrollment) {
            throw AuthError::invalidMfaChallenge();
        }

        $account = Account::query()->findOrFail($challenge->accountId);
        $secret = Totp::generateSecret();
        $this->challenges->put($challengeToken, $challenge->withPendingSecret($secret));

        return [
            'secret' => $secret,
            'otpauth_uri' => Totp::provisioningUri($secret, $account->login_identifier, (string) config('pathology.auth.mfa_issuer')),
        ];
    }

    /** Checks the authenticator code; on enrolment it also switches MFA on. */
    public function completeMfa(string $challengeToken, string $code): IssuedTokens
    {
        $challenge = $this->challenges->get($challengeToken);
        $account = Account::query()->findOrFail($challenge->accountId);

        if ($challenge->isEnrollment && $challenge->pendingSecret === null) {
            throw AuthError::mfaEnrollmentNotStarted();
        }

        $secret = $challenge->isEnrollment ? $challenge->pendingSecret : $account->mfa_secret;

        if ($secret === null || ! Totp::verify($secret, $code, CarbonImmutable::now()->getTimestamp())) {
            $this->recordFailedMfaAttempt($challengeToken, $challenge);

            throw AuthError::invalidMfaCode();
        }

        $this->challenges->forget($challengeToken);
        $this->activeUserFor($account);

        if ($challenge->isEnrollment) {
            $account->forceFill(['mfa_secret' => $secret, 'mfa_enabled' => true])->save();
        }

        return $this->completeSignIn($account, $challenge->ipAddress, $challenge->deviceInfo);
    }

    private function completeSignIn(Account $account, ?string $ipAddress, ?string $deviceInfo): IssuedTokens
    {
        $account->forceFill(['last_login_at' => now()])->save();

        return $this->tokenIssuer->startSession($account, $ipAddress, $deviceInfo);
    }

    /** The staff member behind the account, who must still be active. */
    private function activeUserFor(Account $account): User
    {
        // Nobody is signed in yet, so look the user up with an explicit system scope.
        $user = $this->currentScope->runAs(
            ScopeContext::system(),
            fn (): ?User => User::query()->with('role.permissionEntries')->find($account->owner_id),
        );

        if (! $account->is_active || $user === null || $user->status !== UserStatus::Active) {
            throw AuthError::accountDisabled();
        }

        return $user;
    }

    private function recordFailedAttempt(Account $account): void
    {
        $attempts = $account->failed_attempts + 1;
        $locks = $attempts >= (int) config('pathology.auth.max_failed_logins');

        $account->forceFill([
            'failed_attempts' => $locks ? 0 : $attempts,
            'locked_until' => $locks ? now()->addMinutes((int) config('pathology.auth.lockout_minutes')) : null,
        ])->save();
    }

    private function recordFailedMfaAttempt(string $challengeToken, MfaChallenge $challenge): void
    {
        $updated = $challenge->withFailedAttempt();

        if ($updated->failedAttempts >= (int) config('pathology.auth.mfa_max_attempts')) {
            $this->challenges->forget($challengeToken);

            return;
        }

        $this->challenges->put($challengeToken, $updated);
    }
}
