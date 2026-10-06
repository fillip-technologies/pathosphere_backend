<?php

namespace App\Modules\Lab\Services;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Services\OrderReporting;
use App\Modules\Booking\Services\ReportOrderFacts;
use App\Modules\Lab\Enums\ReportStatus;
use App\Modules\Lab\Errors\LabError;
use App\Modules\Lab\Events\ReportReleased;
use App\Modules\Lab\Models\Report;
use App\Modules\Lab\Models\WorklistEntry;
use App\Modules\Lab\StateMachines\ReportStateMachine;
use App\Modules\Network\Services\NetworkDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Releases a fully signed report (spec §5.5 step 5) or, for a B2B client
 * whose policy says so and who has overdue invoices, withholds it until the
 * dues are cleared (spec §12 open decision: B2B only, per client).
 *
 * Release marks the report's tests reported and moves the order to
 * partially reported or completed; rendering and delivery follow after
 * commit.
 */
final class ReportReleaseService
{
    public function __construct(
        private readonly LabGuard $labGuard,
        private readonly OrderReporting $orders,
        private readonly NetworkDirectory $network,
        private readonly ReportAssembly $reports,
        private readonly ReportStateMachine $reportStates,
    ) {}

    public function release(StaffContext $staff, Report $report): Report
    {
        $this->labGuard->assertActsFor($report->processing_branch_id, 'release reports');

        return DB::transaction(function () use ($staff, $report): Report {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);

            if (! in_array($report->status, [ReportStatus::Signed, ReportStatus::Withheld], true)) {
                throw LabError::reportNotReleasable();
            }

            $order = $this->orders->reportFacts($report->organization_id, $report->order_id);

            if ($this->mustWithhold($order)) {
                if ($report->status === ReportStatus::Withheld) {
                    throw LabError::reportWithheld();
                }

                $this->reportStates->transition($report, ReportStatus::Withheld);

                return $report;
            }

            $reportedItemIds = $this->reports->liveEntries($report)->map(fn (WorklistEntry $entry) => $entry->order_item_id)->values()->all();
            $expectedItemIds = $this->orders->reportableItemIdsAtLab($report->organization_id, $report->order_id, $report->processing_branch_id);

            $this->reportStates->transition($report, ReportStatus::Released, [
                'is_partial' => array_diff($expectedItemIds, $reportedItemIds) !== [],
                'released_at' => CarbonImmutable::now(),
                'released_by' => $staff->user()->id,
            ]);
            $this->orders->markItemsReported($report->organization_id, $report->order_id, $reportedItemIds);
            event(new ReportReleased($report->id, $report->organization_id));

            return $report;
        });
    }

    private function mustWithhold(ReportOrderFacts $order): bool
    {
        return $order->b2bClientId !== null
            && $this->network->b2bClientWithholdsReports($order->b2bClientId)
            && $this->orders->b2bClientHasOverdueInvoices($order->organizationId, $order->b2bClientId);
    }
}
