<?php

namespace App\Modules\Lab\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Raised when a report version is released (spec §9): its PDF is rendered
 * and stored, and the patient and doctor are sent a link. A version after
 * the first is an amendment (spec ReportAmended): everyone is told the
 * report changed.
 */
final class ReportReleased implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $reportId,
        public readonly string $organizationId,
    ) {}
}
