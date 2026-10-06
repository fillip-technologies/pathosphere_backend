<?php

namespace App\Modules\Lab\Services;

use App\Modules\Booking\Services\OrderReporting;
use App\Modules\Catalogue\Services\TestDirectory;
use App\Modules\Lab\Enums\ReportStatus;
use App\Modules\Lab\Models\LabResult;
use App\Modules\Lab\Models\Report;
use App\Modules\Lab\Models\WorklistEntry;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Closure;

/**
 * Released reports as the patient's health locker keeps them (spec §7.11:
 * "copied from final lab_results; powers trend charts"). Read at
 * organization level: the locker is filled by a system job, and patients
 * reach the copy only through their own records.
 */
final class ReleasedReports
{
    public function __construct(
        private readonly CurrentScope $currentScope,
        private readonly ReportAssembly $reports,
        private readonly OrderReporting $orders,
        private readonly TestDirectory $tests,
        private readonly NetworkDirectory $network,
    ) {}

    /**
     * The report's printed results, or null if it was never released.
     *
     * An amended version is copied with the results as they stand now: the
     * lab keeps one worklist per order and lab across versions. Older
     * versions are superseded in the locker, so only the live one is shown.
     */
    public function lockerCopy(string $organizationId, string $reportId): ?ReleasedReportCopy
    {
        return $this->currentScope->runAs(ScopeContext::system($organizationId), function () use ($reportId): ?ReleasedReportCopy {
            $report = Report::query()->find($reportId);

            if ($report === null || ! $report->status->isPublished() || $report->released_at === null) {
                return null;
            }

            $order = $this->orders->reportFacts($report->organization_id, $report->order_id);
            $entries = $this->reports->liveEntries($report);
            $definitions = $this->tests->resultDefinitions($entries->pluck('test_id')->all());
            $results = LabResult::query()
                ->whereIn('worklist_entry_id', $entries->pluck('id'))
                ->where('is_final', true)
                ->get()
                ->groupBy('worklist_entry_id');
            $lines = [];

            foreach ($entries as $entry) {
                /** @var WorklistEntry $entry */
                $definition = $definitions[$entry->test_id];
                $byParameter = $results->get($entry->id, collect())->keyBy('test_parameter_id');

                foreach ($definition->parameters as $parameter) {
                    $result = $byParameter->get($parameter->id);

                    if ($result === null) {
                        continue;
                    }

                    $lines[] = new ReleasedResult(
                        $definition->code,
                        $definition->name,
                        $parameter->code,
                        $parameter->name,
                        $result->value,
                        $result->value_numeric,
                        $result->unit,
                        $result->ref_range_text,
                        $result->flag?->value,
                    );
                }
            }

            $siblings = Report::query()
                ->where('order_id', $report->order_id)
                ->where('processing_branch_id', $report->processing_branch_id)
                ->whereIn('status', [ReportStatus::Released, ReportStatus::Amended])
                ->pluck('id', 'version');

            return new ReleasedReportCopy(
                $report->id,
                $report->organization_id,
                $report->patient_id,
                $report->version,
                $order->orderDate,
                $report->released_at,
                $this->network->letterhead($report->processing_branch_id)->name,
                array_values(array_unique(array_map(fn ($definition) => $definition->name, $definitions))),
                $siblings->get($report->version - 1),
                $siblings->get($report->version + 1),
                $report->pdf_path !== null,
                $lines,
            );
        });
    }

    /**
     * Every released or amended report, oldest version first, to fill the
     * locker with reports released before it existed.
     *
     * @param  Closure(string $reportId, string $organizationId): void  $each
     */
    public function eachPublished(Closure $each): void
    {
        $this->currentScope->runAs(ScopeContext::system(), fn () => Report::query()
            ->whereIn('status', [ReportStatus::Released, ReportStatus::Amended])
            ->whereNotNull('released_at')
            ->orderBy('released_at')
            ->orderBy('version')
            ->select(['id', 'organization_id'])
            ->chunk(500, function ($reports) use ($each): void {
                foreach ($reports as $report) {
                    $each($report->id, $report->organization_id);
                }
            }));
    }
}
