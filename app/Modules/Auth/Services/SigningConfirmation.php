<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Domain\Totp;
use App\Modules\Auth\Errors\AuthError;
use Carbon\CarbonImmutable;

/**
 * Signing a report asks for the authenticator code again (spec §10.4), so a
 * signed-in session left open cannot sign on its own.
 */
final class SigningConfirmation
{
    public function __construct(private readonly StaffAccounts $accounts) {}

    public function confirm(StaffContext $staff, string $code): void
    {
        $account = $this->accounts->for($staff->user());

        if (! $account->mfa_enabled || $account->mfa_secret === null) {
            throw AuthError::mfaNotSetUp();
        }

        if (! Totp::verify($account->mfa_secret, $code, CarbonImmutable::now()->getTimestamp())) {
            throw AuthError::invalidMfaCode();
        }
    }
}
