<?php

namespace App\Modules\Network\Contracts;

final class KycCheckResult
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    /** The vendor has no automated check for this kind of paper (e.g. premises photos). */
    public const NOT_CHECKED = 'not_checked';

    public function __construct(
        public readonly string $outcome,
        public readonly ?string $reference = null,
    ) {}

    public function passed(): bool
    {
        return $this->outcome === self::PASSED;
    }
}
