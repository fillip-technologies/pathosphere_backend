<?php

namespace App\Modules\Lab\Domain;

use App\Modules\Catalogue\Enums\SigningDiscipline;
use Carbon\CarbonImmutable;

/** One signatory row: who may sign which department at which lab, until when. */
final class SignatoryCredential
{
    public function __construct(
        public readonly string $signatoryId,
        public readonly string $userId,
        public readonly string $labId,
        public readonly string $departmentId,
        public readonly SigningDiscipline $discipline,
        public readonly ?CarbonImmutable $validTill,
        public readonly bool $isActive,
    ) {}
}
