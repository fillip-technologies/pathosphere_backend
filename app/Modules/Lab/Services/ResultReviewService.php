<?php

namespace App\Modules\Lab\Services;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Lab\Enums\ReportStatus;
use App\Modules\Lab\Enums\WorklistStatus;
use App\Modules\Lab\Errors\LabError;
use App\Modules\Lab\Models\LabResult;
use App\Modules\Lab\Models\WorklistEntry;
use App\Modules\Lab\StateMachines\WorklistEntryStateMachine;
use App\Modules\Samples\Services\LabSamples;
use App\Modules\Shared\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The supervisor's side of results (spec §5.5 step 3): verify them, or send
 * a test back for a rerun. Verifying a test's last result moves its report
 * towards signing; a rerun pulls the report back to draft and voids that
 * department's signature.
 */
final class ResultReviewService
{
    public function __construct(
        private readonly LabGuard $labGuard,
        private readonly LabSamples $samples,
        private readonly ReportAssembly $reports,
        private readonly WorklistEntryStateMachine $entryStates,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function verifyResult(StaffContext $staff, LabResult $result): LabResult
    {
        $this->labGuard->assertActsFor($result->processing_branch_id, 'verify results');

        return DB::transaction(function () use ($staff, $result): LabResult {
            $entry = WorklistEntry::query()->lockForUpdate()->findOrFail($result->worklist_entry_id);
            $result->refresh();

            if ($result->run_no !== $entry->current_run || $entry->status === WorklistStatus::Withdrawn) {
                throw LabError::resultNotCurrent();
            }

            if ($result->isVerified()) {
                return $result;
            }

            $this->markVerified($staff, $result);
            $this->afterVerification($entry);

            return $result;
        });
    }

    /** Verifies every result of the test's current run at once. */
    public function verifyTest(StaffContext $staff, WorklistEntry $entry): WorklistEntry
    {
        $this->labGuard->assertActsFor($entry->processing_branch_id, 'verify results');

        return DB::transaction(function () use ($staff, $entry): WorklistEntry {
            $entry = WorklistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->status === WorklistStatus::Verified) {
                return $entry;
            }

            if ($entry->status !== WorklistStatus::Entered) {
                throw $entry->status === WorklistStatus::Pending ? LabError::nothingToVerify() : LabError::testNotOpen();
            }

            foreach ($this->currentResults($entry) as $result) {
                if (! $result->isVerified()) {
                    $this->markVerified($staff, $result);
                }
            }

            $this->afterVerification($entry);

            return $entry;
        });
    }

    /**
     * A supervisor doubts the results: the next run starts from the same
     * sample, the current one is kept but no longer prints (spec §7.6
     * run_no history). Not possible once the report is released; amend it
     * first.
     */
    public function rerun(StaffContext $staff, WorklistEntry $entry, string $reason): WorklistEntry
    {
        $this->labGuard->assertActsFor($entry->processing_branch_id, 'rerun tests');

        return DB::transaction(function () use ($entry, $reason): WorklistEntry {
            $entry = WorklistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->status === WorklistStatus::Withdrawn) {
                throw LabError::testNotOpen();
            }

            $report = $this->reports->current($entry->organization_id, $entry->order_id, $entry->processing_branch_id);

            if ($report !== null && $report->status === ReportStatus::Released) {
                throw LabError::reportReleased();
            }

            $results = $this->currentResults($entry);

            if ($results->isEmpty()) {
                throw LabError::rerunNotNeeded();
            }

            $results->each(fn (LabResult $result) => $result->forceFill(['is_final' => false])->save());
            $previousRun = $entry->current_run;
            $entry->forceFill(['current_run' => $previousRun + 1]);

            if ($entry->status === WorklistStatus::Pending) {
                $entry->save();
            } else {
                $this->entryStates->transition($entry, WorklistStatus::Pending);
            }

            $this->auditLogger->record('worklist_entry.rerun', $entry, ['current_run' => $previousRun], ['current_run' => $entry->current_run, 'reason' => $reason]);
            $this->samples->markInProcess($entry->organization_id, $entry->sample_id);

            if ($report !== null) {
                $this->reports->revokeSignatures($report, [$entry->department_id], 'rerun');
                $this->reports->refresh($report);
            }

            return $entry;
        });
    }

    private function markVerified(StaffContext $staff, LabResult $result): void
    {
        $result->forceFill(['verified_by' => $staff->user()->id, 'verified_at' => CarbonImmutable::now()])->save();
        $this->auditLogger->recordChanges('result.verify', $result);
    }

    /**
     * A complete test whose results are all verified is verified; then its
     * sample may be finished and its report may be ready to sign.
     */
    private function afterVerification(WorklistEntry $entry): void
    {
        $results = $this->currentResults($entry);

        if ($entry->status !== WorklistStatus::Entered || $results->contains(fn (LabResult $result) => ! $result->isVerified())) {
            $entry->touch();

            return;
        }

        $this->entryStates->transition($entry, WorklistStatus::Verified);

        $sampleHasOpenTests = WorklistEntry::query()
            ->where('sample_id', $entry->sample_id)
            ->whereIn('status', [WorklistStatus::Pending, WorklistStatus::Entered])
            ->exists();

        if (! $sampleHasOpenTests) {
            $this->samples->markProcessed($entry->organization_id, $entry->sample_id);
        }

        $report = $this->reports->current($entry->organization_id, $entry->order_id, $entry->processing_branch_id);

        if ($report !== null) {
            $this->reports->refresh($report);
        }
    }

    /** @return Collection<int, LabResult> */
    private function currentResults(WorklistEntry $entry): Collection
    {
        return LabResult::query()
            ->where('worklist_entry_id', $entry->id)
            ->where('run_no', $entry->current_run)
            ->orderBy('id')
            ->get();
    }
}
