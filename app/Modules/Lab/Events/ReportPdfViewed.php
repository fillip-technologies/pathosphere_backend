<?php

namespace App\Modules\Lab\Events;

/**
 * Someone opened a released report's PDF: staff in the app, or anyone with
 * a signed link (spec §10.7: every health-record view is logged). Handled
 * synchronously, so the view is on record before the file leaves.
 */
final class ReportPdfViewed
{
    /** @param  string  $via  'staff' or 'signed_link' */
    public function __construct(
        public readonly string $reportId,
        public readonly string $organizationId,
        public readonly string $via,
        public readonly ?string $viewerUserId,
        public readonly ?string $ipAddress,
    ) {}
}
