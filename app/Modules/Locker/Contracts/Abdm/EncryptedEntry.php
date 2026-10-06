<?php

namespace App\Modules\Locker\Contracts\Abdm;

/** One care context's FHIR document, encrypted for the receiver. */
final class EncryptedEntry
{
    public function __construct(
        public readonly string $careContextReference,
        /** Base64 ciphertext with its authentication tag. */
        public readonly string $content,
        /** MD5 of the plain document, hex. */
        public readonly string $checksum,
    ) {}
}
