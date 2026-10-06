<?php

namespace App\Modules\Locker\Contracts\Abdm;

use Carbon\CarbonImmutable;

/** One side's half of the key agreement for encrypted health data (ECDH on Curve25519). */
final class KeyMaterial
{
    public function __construct(
        public readonly string $cryptoAlgorithm,
        public readonly string $curve,
        /** Base64. */
        public readonly string $publicKey,
        /** Base64, 32 random bytes. */
        public readonly string $nonce,
        public readonly ?CarbonImmutable $expiresAt,
    ) {}
}
