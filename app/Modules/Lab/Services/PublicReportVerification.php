<?php

namespace App\Modules\Lab\Services;

use App\Modules\Booking\Services\OrderReporting;
use App\Modules\Catalogue\Services\TestDirectory;
use App\Modules\Lab\Enums\ReportStatus;
use App\Modules\Lab\Models\Report;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;

/**
 * The public page behind a report's QR code (spec §8 Public): confirms the
 * report is genuine with the patient's initials, the test names, the release
 * date and the lab, and says when a newer version replaced it. Never shows
 * results.
 */
final class PublicReportVerification
{
    public function __construct(
        private readonly CurrentScope $currentScope,
        private readonly ReportAssembly $reports,
        private readonly OrderReporting $orders,
        private readonly TestDirectory $tests,
        private readonly NetworkDirectory $network,
    ) {}

    /** @return array<string, mixed>|null null when no released report has this code */
    public function lookup(string $qrCode): ?array
    {
        return $this->currentScope->runAs(ScopeContext::system(), function () use ($qrCode): ?array {
            $report = Report::query()->where('qr_code', $qrCode)->first();

            if ($report === null || ! $report->status->isPublished() || $report->released_at === null) {
                return null;
            }

            $order = $this->orders->reportFacts($report->organization_id, $report->order_id);
            $lab = $this->network->letterhead($report->processing_branch_id);
            $testIds = $this->reports->liveEntries($report)->pluck('test_id')->all();
            $latestVersion = $report->status === ReportStatus::Amended
                ? (int) Report::query()->where('order_id', $report->order_id)->where('processing_branch_id', $report->processing_branch_id)->max('version')
                : $report->version;

            return [
                'status' => $report->status === ReportStatus::Amended ? 'superseded' : 'valid',
                'version' => $report->version,
                'latest_version' => $latestVersion,
                'patient_initials' => $order->patientInitials(),
                'tests' => array_values(array_map(fn ($test) => $test->name, $this->tests->resultDefinitions($testIds))),
                'released_at' => $report->released_at->toIso8601ZuluString(),
                'lab' => ['name' => $lab->name, 'nabl_certificate_no' => $lab->nablCertificateNo],
                'pdf_sha256' => $report->pdf_sha256,
            ];
        });
    }

    /** A released report reached through a signed link: the link is the proof of access. */
    public function releasedReport(string $reportId): ?Report
    {
        return $this->currentScope->runAs(ScopeContext::system(), fn (): ?Report => Report::query()
            ->whereKey($reportId)
            ->whereIn('status', [ReportStatus::Released, ReportStatus::Amended])
            ->first());
    }
}
