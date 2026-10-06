<?php

namespace App\Modules\Lab\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Raised when results fall outside critical limits (spec §5.5 step 2, §9):
 * the booking branch, the lab and the referring doctor are alerted at once.
 */
final class ResultCritical implements ShouldDispatchAfterCommit
{
    /** @param  list<string>  $resultIds */
    public function __construct(
        public readonly string $worklistEntryId,
        public readonly string $organizationId,
        public readonly array $resultIds,
    ) {}
}
