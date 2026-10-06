<?php

namespace App\Modules\Locker\Services;

use Carbon\CarbonImmutable;

/**
 * One patient's connection to their DigiLocker, kept encrypted in the cache
 * for its short life: waiting for the patient to sign in at DigiLocker, then
 * connected until DigiLocker's access ends. Never stored in the database.
 */
final class DigiLockerSession
{
    public const AWAITING_AUTHORIZATION = 'awaiting_authorization';

    public const CONNECTED = 'connected';

    public function __construct(
        public readonly string $id,
        /** The signed-in patient (owner of the phone) and the profile documents go to. */
        public readonly string $holderId,
        public readonly string $profileId,
        public readonly string $state,
        public readonly string $codeVerifier,
        public readonly string $authorizationUrl,
        public readonly CarbonImmutable $expiresAt,
        public readonly ?string $accessToken = null,
        public readonly ?string $digiLockerId = null,
        public readonly ?string $accountName = null,
    ) {}

    public function status(): string
    {
        return $this->accessToken === null ? self::AWAITING_AUTHORIZATION : self::CONNECTED;
    }

    public function connected(string $accessToken, CarbonImmutable $accessExpiresAt, string $digiLockerId, ?string $accountName): self
    {
        return new self($this->id, $this->holderId, $this->profileId, $this->state, $this->codeVerifier, $this->authorizationUrl, $accessExpiresAt, $accessToken, $digiLockerId, $accountName);
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'holder_id' => $this->holderId,
            'profile_id' => $this->profileId,
            'state' => $this->state,
            'code_verifier' => $this->codeVerifier,
            'authorization_url' => $this->authorizationUrl,
            'expires_at' => $this->expiresAt->toIso8601ZuluString(),
            'access_token' => $this->accessToken,
            'digilocker_id' => $this->digiLockerId,
            'account_name' => $this->accountName,
        ];
    }

    /** @param  array<string, string|null>  $stored */
    public static function fromArray(array $stored): self
    {
        return new self(
            (string) $stored['id'],
            (string) $stored['holder_id'],
            (string) $stored['profile_id'],
            (string) $stored['state'],
            (string) $stored['code_verifier'],
            (string) $stored['authorization_url'],
            CarbonImmutable::parse((string) $stored['expires_at']),
            $stored['access_token'],
            $stored['digilocker_id'],
            $stored['account_name'],
        );
    }
}
