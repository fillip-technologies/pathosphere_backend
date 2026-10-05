<?php

namespace App\Modules\Catalogue\Domain;

/** One line the client asked for: a test or a package, never both. */
final class QuoteRequestItem
{
    private function __construct(
        public readonly ?string $testId,
        public readonly ?string $packageId,
    ) {}

    public static function test(string $testId): self
    {
        return new self($testId, null);
    }

    public static function package(string $packageId): self
    {
        return new self(null, $packageId);
    }
}
