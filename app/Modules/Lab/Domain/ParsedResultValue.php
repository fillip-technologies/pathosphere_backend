<?php

namespace App\Modules\Lab\Domain;

/** A reported value as stored: the raw text and, for numbers, the parsed decimal. */
final class ParsedResultValue
{
    public function __construct(
        public readonly string $value,
        public readonly ?string $numeric,
    ) {}
}
