<?php

namespace App\Modules\Shared\Files;

/** A file written to private storage, with what callers need to save on their row. */
final class StoredFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $sha256,
        public readonly int $sizeBytes,
    ) {}
}
