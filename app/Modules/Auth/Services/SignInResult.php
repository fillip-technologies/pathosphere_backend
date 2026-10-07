<?php

namespace App\Modules\Auth\Services;

/**
 * Outcome of a password sign-in: either tokens, or an MFA step the client
 * must complete with the challenge token.
 */
final class SignInResult
{
    public const AUTHENTICATED = 'authenticated';

    public const MFA_REQUIRED = 'mfa_required';

    private function __construct(
        public readonly string $status,
        public readonly ?IssuedTokens $tokens = null,
        public readonly ?string $mfaChallengeToken = null,
    ) {}

    public static function authenticated(IssuedTokens $tokens): self
    {
        return new self(self::AUTHENTICATED, tokens: $tokens);
    }

    public static function mfaRequired(string $challengeToken): self
    {
        return new self(self::MFA_REQUIRED, mfaChallengeToken: $challengeToken);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'tokens' => $this->tokens?->toArray(),
            'mfa_challenge_token' => $this->mfaChallengeToken,
        ], fn ($value) => $value !== null);
    }
}
