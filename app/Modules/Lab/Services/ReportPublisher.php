<?php

namespace App\Modules\Lab\Services;

use App\Modules\Booking\Services\OrderFulfilment;
use App\Modules\Booking\Services\OrderReporting;
use App\Modules\Lab\Contracts\PdfRenderer;
use App\Modules\Lab\Models\Report;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Files\PrivateFileStore;
use App\Modules\Shared\Files\PrivatePaths;
use App\Modules\Shared\Files\StoredFile;
use App\Modules\Shared\Notifications\Notification;
use App\Modules\Shared\Notifications\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * After release (spec §9 ReportReleased): render the PDF once, store it
 * under private/reports/{yyyy}/{mm}/{id}_v{n}.pdf with its hash, then send
 * the patient and the referring doctor a short-lived link.
 *
 * Safe to retry: a stored PDF is never rendered again (spec §10.6), and a
 * report whose messages were already queued is not sent twice.
 */
final class ReportPublisher
{
    public function __construct(
        private readonly ReportDocumentBuilder $documents,
        private readonly PdfRenderer $pdfRenderer,
        private readonly PrivateFileStore $files,
        private readonly OrderFulfilment $fulfilment,
        private readonly OrderReporting $orders,
        private readonly NetworkDirectory $network,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function publish(string $reportId): void
    {
        $report = Report::query()->findOrFail($reportId);

        if (! $report->status->isPublished() || $report->released_at === null) {
            return;
        }

        if ($report->pdf_path === null) {
            $this->storePdf($report);
        }

        $this->deliver($report);
    }

    /** A signed link to the report's PDF, valid for the configured hours (spec §9 rule 2: 24–72 h). */
    public function downloadLink(Report $report): string
    {
        return URL::temporarySignedRoute(
            'report-links.show',
            CarbonImmutable::now()->addHours((int) config('pathology.lab.report_link_hours')),
            ['reportId' => $report->id],
        );
    }

    private function storePdf(Report $report): void
    {
        $path = PrivatePaths::report($report->id, $report->version, $report->released_at);

        // A previous attempt may have written the file and failed before saving the row.
        $stored = $this->files->exists($path)
            ? new StoredFile($path, $this->files->sha256($path), 0)
            : $this->files->putNew($path, $this->pdfRenderer->render($this->documents->html($report)));

        $report->forceFill(['pdf_path' => $stored->path, 'pdf_sha256' => $stored->sha256])->save();
        $this->auditLogger->recordChanges('report.pdf_stored', $report);
    }

    private function deliver(Report $report): void
    {
        if (Notification::query()->where('payload->report_id', $report->id)->exists()) {
            return;
        }

        $order = $this->orders->reportFacts($report->organization_id, $report->order_id);
        $variables = [
            'patient_name' => $order->patientName,
            'order_no' => $order->orderNo,
            'lab_name' => $this->network->letterhead($report->processing_branch_id)->name,
            'report_url' => $this->downloadLink($report),
            'report_id' => $report->id,
        ];
        $isAmendment = $report->version > 1;

        $this->notifications->notify(
            $isAmendment ? 'report_amended' : 'report_ready',
            $this->fulfilment->patientRecipient($report->organization_id, $report->order_id),
            $variables,
        );

        $doctor = $this->orders->doctorRecipient($report->organization_id, $report->order_id);

        if ($doctor !== null) {
            $this->notifications->notify('doctor_report_ready', $doctor, $variables + ['amended_note' => $isAmendment ? ' (amended)' : '']);
        }
    }
}
