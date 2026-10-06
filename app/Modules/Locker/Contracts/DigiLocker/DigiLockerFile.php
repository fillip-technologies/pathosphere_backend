<?php

namespace App\Modules\Locker\Contracts\DigiLocker;

final class DigiLockerFile
{
    public function __construct(
        public readonly string $contents,
        public readonly string $mimeType,
    ) {}
}
