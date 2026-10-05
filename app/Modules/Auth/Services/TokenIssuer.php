<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Errors\AuthError;
use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Models\AuthSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Short-lived access tokens (15 minutes) plus a rotating refresh token per
 * signed-in device (spec §8.1). Only the SHA-256 of a refresh token is
 * stored (spec §10.3).
 */
final class TokenIssuer
{
    public function startSession(Account $account, ?string $ipAddress, ?string $deviceInfo): IssuedTokens
    {
        return DB::transaction(function () use ($account, $ipAddress, $deviceInfo): IssuedTokens {
            $refreshToken = $this->newRefreshToken();

            $session = AuthSession::query()->create([
                'account_id' => $account->id,
                'refresh_token_hash' => hash('sha256', $refreshToken),
                'device_info' => $deviceInfo === null ? null : mb_substr($deviceInfo, 0, 255),
                'ip_address' => $ipAddress,
                'expires_at' => $this->refreshTokenExpiry(),
            ]);

            return $this->issue($account, $session, $refreshToken);
        });
    }

    /**
     * Swaps a refresh token for a new pair. The old refresh token and the
     * session's old access tokens stop working immediately.
     */
    public function rotate(string $refreshToken): IssuedTokens
    {
        return DB::transaction(function () use ($refreshToken): IssuedTokens {
            $session = AuthSession::query()
                ->where('refresh_token_hash', hash('sha256', $refreshToken))
                ->lockForUpdate()
                ->first();

            if ($session === null || ! $session->isUsable() || ! $session->account->is_active) {
                throw AuthError::invalidRefreshToken();
            }

            $newRefreshToken = $this->newRefreshToken();
            $session->update([
                'refresh_token_hash' => hash('sha256', $newRefreshToken),
                'expires_at' => $this->refreshTokenExpiry(),
            ]);
            $this->deleteAccessTokens($session);

            return $this->issue($session->account, $session, $newRefreshToken);
        });
    }

    public function revoke(AuthSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $session->update(['revoked_at' => now()]);
            $this->deleteAccessTokens($session);
        });
    }

    /** Signs an account out everywhere, e.g. when a user is disabled. */
    public function revokeAllFor(Account $account): void
    {
        AuthSession::query()
            ->where('account_id', $account->id)
            ->whereNull('revoked_at')
            ->get()
            ->each(fn (AuthSession $session) => $this->revoke($session));
    }

    public function sessionForAccessToken(PersonalAccessToken $token): ?AuthSession
    {
        $sessionId = Str::after($token->name, 'session:');

        return AuthSession::query()->find($sessionId);
    }

    private function issue(Account $account, AuthSession $session, string $refreshToken): IssuedTokens
    {
        $accessExpiresAt = CarbonImmutable::now()->addMinutes((int) config('pathology.auth.access_token_minutes'));
        $accessToken = $account->createToken($session->accessTokenName(), ['*'], $accessExpiresAt);

        return new IssuedTokens($accessToken->plainTextToken, $accessExpiresAt, $refreshToken, $session->expires_at);
    }

    private function deleteAccessTokens(AuthSession $session): void
    {
        PersonalAccessToken::query()->where('name', $session->accessTokenName())->delete();
    }

    private function newRefreshToken(): string
    {
        return Str::random(64);
    }

    private function refreshTokenExpiry(): CarbonImmutable
    {
        return CarbonImmutable::now()->addDays((int) config('pathology.auth.refresh_token_days'));
    }
}
