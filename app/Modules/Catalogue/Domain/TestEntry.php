<?php

namespace App\Modules\Catalogue\Domain;

/** The facts about a test that pricing and routing need. */
final class TestEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly bool $isActive,
        public readonly int $tatHours,
        public readonly string $sampleType,
        public readonly string $containerType,
    ) {}
}
