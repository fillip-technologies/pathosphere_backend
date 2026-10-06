<?php

namespace App\Modules\Locker\Contracts\Abdm;

/** Why we could not do what ABDM asked; sent back in our answer. */
final class HipError
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {}
}
