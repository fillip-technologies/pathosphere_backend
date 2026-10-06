<?php

namespace App\Modules\Locker\Contracts\Abdm;

/** One page of encrypted records pushed to a health information user. */
final class HealthDataPage
{
    /** @param  list<EncryptedEntry>  $entries */
    public function __construct(
        public readonly string $transactionId,
        public readonly int $pageNumber,
        public readonly int $pageCount,
        public readonly array $entries,
        /** Our public key and nonce, so the receiver can derive the same key. */
        public readonly KeyMaterial $keyMaterial,
    ) {}
}
