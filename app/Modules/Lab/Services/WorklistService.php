<?php

namespace App\Modules\Lab\Services;

use App\Modules\Booking\Services\OrderReporting;
use App\Modules\Catalogue\Services\TestDirectory;
use App\Modules\Lab\Enums\WorklistStatus;
use App\Modules\Lab\Models\LabResult;
use App\Modules\Lab\Models\Report;
use App\Modules\Lab\Models\WorklistEntry;
use App\Modules\Lab\StateMachines\WorklistEntryStateMachine;
use App\Modules\Samples\Enums\SampleStatus;
use App\Modules\Samples\Services\LabSamples;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Illuminate\Support\Facades\DB;

/**
 * Puts tests on a lab's worklist when their sample arrives, and takes them
 * off when the sample leaves unworked (re-routed onward, or rejected).
 * Runs from sample events, as the system.
 */
final class WorklistService
{
    public function __construct(
        private readonly CurrentScope $currentScope,
        private readonly LabSamples $samples,
        private readonly OrderReporting $orders,
        private readonly TestDirectory $tests,
        private readonly ReportAssembly $reports,
        private readonly WorklistEntryStateMachine $entryStates,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * The sample was accepted at the lab that tests it: each of its tests
     * waits for results, the order's items move to processing, and the
     * lab's report for the order is open for them.
     *
     * @return int tests added to the worklist
     */
    public function openForSample(string $organizationId, string $sampleId): int
    {
        $sample = $this->samples->facts($organizationId, $sampleId);

        if ($sample === null || ! $sample->isAtItsLab()) {
            return 0;
        }

        $items = array_filter(
            $this->orders->labItems($organizationId, $sample->orderItemIds),
            fn ($item) => $item->isReportable && $item->processingBranchId === $sample->processingBranchId,
        );

        if ($items === []) {
            return 0;
        }

        $definitions = $this->tests->resultDefinitions(array_values(array_map(fn ($item) => $item->testId, $items)));

        return $this->inOrganization($organizationId, fn (): int => DB::transaction(function () use ($organizationId, $sample, $items, $definitions): int {
            $alreadyOpen = WorklistEntry::query()
                ->whereIn('order_item_id', array_keys($items))
                ->where('status', '!=', WorklistStatus::Withdrawn)
                ->pluck('order_item_id')
                ->all();
            $opened = [];

            foreach ($items as $item) {
                if (in_array($item->id, $alreadyOpen, true)) {
                    continue;
                }

                $entry = new WorklistEntry([
                    'current_run' => $this->nextRun($item->id),
                    'due_at' => $item->dueAt,
                    'status' => WorklistStatus::Pending,
                ]);
                $entry->forceFill([
                    'organization_id' => $organizationId,
                    'order_id' => $item->orderId,
                    'order_item_id' => $item->id,
                    'sample_id' => $sample->id,
                    'processing_branch_id' => $sample->processingBranchId,
                    'test_id' => $item->testId,
                    'department_id' => $definitions[$item->testId]->departmentId,
                ])->save();
                $this->auditLogger->recordCreated('worklist_entry.open', $entry);
                $opened[] = $entry->order_item_id;
            }

            if ($opened === []) {
                return 0;
            }

            $this->orders->markItemsProcessing($organizationId, $opened);
            $this->reports->openForNewTests($organizationId, $sample->orderId, $sample->processingBranchId);

            return count($opened);
        }));
    }

    /**
     * The sample left the lab without results: re-routed to another lab, or
     * rejected and redrawn. Its unfinished tests here are withdrawn.
     *
     * @return int tests withdrawn
     */
    public function withdrawForSample(string $organizationId, string $sampleId): int
    {
        $sample = $this->samples->facts($organizationId, $sampleId);

        if ($sample === null) {
            return 0;
        }

        return $this->inOrganization($organizationId, fn (): int => DB::transaction(function () use ($sample): int {
            $entries = WorklistEntry::query()
                ->where('sample_id', $sample->id)
                ->whereIn('status', [WorklistStatus::Pending, WorklistStatus::Entered])
                ->when(
                    $sample->status !== SampleStatus::Rejected,
                    fn ($query) => $query->where('processing_branch_id', '!=', $sample->processingBranchId),
                )
                ->lockForUpdate()
                ->get();

            foreach ($entries as $entry) {
                $this->entryStates->transition($entry, WorklistStatus::Withdrawn);
            }

            foreach ($entries->unique(fn (WorklistEntry $entry) => $entry->order_id.'|'.$entry->processing_branch_id) as $entry) {
                $report = $this->reports->current($entry->organization_id, $entry->order_id, $entry->processing_branch_id);

                if ($report instanceof Report) {
                    $this->reports->refresh($report);
                }
            }

            return $entries->count();
        }));
    }

    /** Earlier runs of the test at another lab keep their numbers; this lab continues after them. */
    private function nextRun(string $orderItemId): int
    {
        return (int) LabResult::query()->where('order_item_id', $orderItemId)->max('run_no') + 1;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inOrganization(string $organizationId, callable $callback): mixed
    {
        return $this->currentScope->runAs(ScopeContext::system($organizationId), $callback);
    }
}
