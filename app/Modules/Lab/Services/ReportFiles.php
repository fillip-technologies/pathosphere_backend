<?php

namespace App\Modules\Lab\Services;

use App\Modules\Booking\Services\OrderReporting;
use App\Modules\Lab\Errors\LabError;
use App\Modules\Lab\Events\ReportPdfViewed;
use App\Modules\Lab\Models\Report;
use App\Modules\Shared\Context\CurrentActor;
use App\Modules\Shared\Files\PrivateFileStore;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves stored report PDFs (spec §10.1: through a controller that checks
 * access and logs it, or by an expiring signed link). Every view raises
 * ReportPdfViewed, which the health locker writes to record_access_logs.
 */
final class ReportFiles
{
    public function __construct(
        private readonly PrivateFileStore $files,
        private readonly OrderReporting $orders,
        private readonly CurrentActor $currentActor,
        private readonly CurrentScope $currentScope,
        private readonly ?Request $request = null,
    ) {}

    /** @param  string  $via  'staff' or 'signed_link' */
    public function download(Report $report, string $via): StreamedResponse
    {
        $response = $this->stream($report);

        event(new ReportPdfViewed($report->id, $report->organization_id, $via, $this->currentActor->userId(), $this->request?->ip()));

        return $response;
    }

    /**
     * A released report's PDF for the health locker, which has already
     * checked that the record is the caller's and logs the access itself.
     */
    public function downloadForLocker(string $organizationId, string $reportId): StreamedResponse
    {
        $report = $this->currentScope->runAs(ScopeContext::system($organizationId), fn (): Report => Report::query()->findOrFail($reportId));

        return $this->stream($report);
    }

    private function stream(Report $report): StreamedResponse
    {
        if (! $report->status->isPublished()) {
            throw LabError::reportNotReleasable();
        }

        if ($report->pdf_path === null) {
            throw LabError::pdfNotReady();
        }

        $orderNo = $this->orders->reportFacts($report->organization_id, $report->order_id)->orderNo;

        return $this->files->download($report->pdf_path, sprintf('report-%s-v%d.pdf', preg_replace('/[^A-Za-z0-9-]/', '-', $orderNo), $report->version));
    }
}
