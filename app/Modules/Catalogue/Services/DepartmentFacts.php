<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Enums\SigningDiscipline;

/** A department as reports and signing need it. */
final class DepartmentFacts
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly SigningDiscipline $signingDiscipline,
        public readonly int $reportOrder,
    ) {}
}
