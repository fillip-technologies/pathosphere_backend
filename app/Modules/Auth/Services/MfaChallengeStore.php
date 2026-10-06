<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Errors\AuthError;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Keeps MFA challenges in the database cache for a few minutes. The client
 * holds only a random token; the cache key is its hash, and the stored
 * challenge is encrypted.
 */
final class MfaChallengeStore
{
    public function create(MfaChallenge $challenge): string
    {
        $token = Str::random(48);
        $this->put($token, $challenge);

        return $token;
    }

    public function get(string $token): MfaChallenge
    {
        $stored = Cache::get($this->key($token));

        if (! is_string($stored)) {
            throw AuthError::invalidMfaChallenge();
        }

        $challenge = unserialize(Crypt::decryptString($stored), ['allowed_classes' => [MfaChallenge::class]]);

        return $challenge instanceof MfaChallenge ? $challenge : throw AuthError::invalidMfaChallenge();
    }

    public function put(string $token, MfaChallenge $challenge): void
    {
        Cache::put(
            $this->key($token),
            Crypt::encryptString(serialize($challenge)),
            now()->addMinutes((int) config('pathology.auth.mfa_challenge_minutes')),
        );
    }

    public function forget(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'mfa_challenge:'.hash('sha256', $token);
    }
}
