<?php

namespace App\Modules\Locker\Contracts\Abdm;

use Carbon\CarbonImmutable;

/** What a patient agreed a health information user may receive from us (ABDM consent artefact). */
final class ConsentArtefact
{
    /**
     * @param  list<string>  $careContextReferences
     * @param  list<string>  $hiTypes
     */
    public function __construct(
        public readonly string $requesterName,
        /** ABDM purpose code, e.g. CAREMGT. */
        public readonly string $purposeCode,
        public readonly ?string $patientAbhaAddress,
        public readonly array $careContextReferences,
        public readonly array $hiTypes,
        public readonly CarbonImmutable $dateFrom,
        public readonly CarbonImmutable $dateTo,
        /** When the receiver must erase the data: the consent ends then. */
        public readonly ?CarbonImmutable $dataEraseAt,
        public readonly string $accessMode,
    ) {}
}
