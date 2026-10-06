<?php

namespace App\Modules\Locker\Domain;

use App\Modules\Booking\Services\AbhaDiscoveryCandidate;

/** Who a discovery request is about, or why we cannot say. */
final class DiscoveryMatch
{
    public const NOT_FOUND = 'PATIENT_NOT_FOUND';

    public const AMBIGUOUS = 'MULTIPLE_PATIENTS_FOUND';

    /** @param  list<string>  $matchedBy  abha_number, mobile, uhid */
    private function __construct(
        public readonly ?AbhaDiscoveryCandidate $patient,
        public readonly array $matchedBy,
        public readonly ?string $errorCode,
    ) {}

    /** @param  list<string>  $matchedBy */
    public static function found(AbhaDiscoveryCandidate $patient, array $matchedBy): self
    {
        return new self($patient, $matchedBy, null);
    }

    public static function notFound(): self
    {
        return new self(null, [], self::NOT_FOUND);
    }

    public static function ambiguous(): self
    {
        return new self(null, [], self::AMBIGUOUS);
    }
}
