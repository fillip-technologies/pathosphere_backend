<?php

namespace App\Modules\Catalogue\Domain;

/** A package and the tests it expands into. */
final class PackageEntry
{
    /** @param  list<TestEntry>  $tests */
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly bool $isActive,
        public readonly array $tests,
    ) {}
}
