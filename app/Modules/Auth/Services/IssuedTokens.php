<?php

namespace App\Modules\Auth\Services;

use Carbon\CarbonImmutable;

/** The token pair handed to a client after sign-in or refresh. */
final class IssuedTokens
{
    public function __construct(
        public readonly string $accessToken,
        public readonly CarbonImmutable $accessTokenExpiresAt,
        public readonly string $refreshToken,
        public readonly CarbonImmutable $refreshTokenExpiresAt,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => $this->accessToken,
            'access_token_expires_at' => $this->accessTokenExpiresAt->utc()->toIso8601ZuluString(),
            'refresh_token' => $this->refreshToken,
            'refresh_token_expires_at' => $this->refreshTokenExpiresAt->utc()->toIso8601ZuluString(),
        ];
    }
}
