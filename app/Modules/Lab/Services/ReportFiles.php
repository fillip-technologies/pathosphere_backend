<?php

namespace App\Modules\Lab\Services;

use App\Modules\Booking\Services\OrderReporting;
use App\Modules\Lab\Errors\LabError;
use App\Modules\Lab\Models\Report;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Files\PrivateFileStore;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves stored report PDFs (spec §10.1: through a controller that checks
 * access and logs it, or by an expiring signed link). Every view is
 * recorded; Phase 7's record_access_logs will take these over.
 */
final class ReportFiles
{
    public function __construct(
        private readonly PrivateFileStore $files,
        private readonly OrderReporting $orders,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param  string  $via  'staff' or 'signed_link' */
    public function download(Report $report, string $via): StreamedResponse
    {
        if (! $report->status->isPublished()) {
            throw LabError::reportNotReleasable();
        }

        if ($report->pdf_path === null) {
            throw LabError::pdfNotReady();
        }

        $this->auditLogger->record('report.pdf_viewed', $report, [], ['via' => $via, 'version' => $report->version]);
        $orderNo = $this->orders->reportFacts($report->organization_id, $report->order_id)->orderNo;

        return $this->files->download($report->pdf_path, sprintf('report-%s-v%d.pdf', preg_replace('/[^A-Za-z0-9-]/', '-', $orderNo), $report->version));
    }
}
