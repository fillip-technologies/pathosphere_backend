<?php

namespace App\Modules\Lab\Services;

use App\Modules\Booking\Services\OrderReporting;
use App\Modules\Lab\Domain\ReportReadiness;
use App\Modules\Lab\Enums\ReportStatus;
use App\Modules\Lab\Enums\WorklistStatus;
use App\Modules\Lab\Models\Report;
use App\Modules\Lab\Models\WorklistEntry;
use App\Modules\Lab\StateMachines\ReportStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps each lab's report for an order in step with its tests (spec §5.5):
 * creates it when the first test arrives, moves it between draft, waiting
 * for signatures and signed as results are verified and signed, and starts
 * a new version when a released report has to change.
 *
 * Callers already hold the order's tests at the lab or the report itself,
 * so reports are read at organization level here.
 */
final class ReportAssembly
{
    /** Statuses that follow the tests; released and amended versions never change. */
    private const OPEN = [ReportStatus::Draft, ReportStatus::PendingSignature, ReportStatus::Signed, ReportStatus::Withheld];

    public const ADDED_TESTS_REASON = 'Tests added after the previous version was released.';

    public function __construct(
        private readonly CurrentScope $currentScope,
        private readonly OrderReporting $orders,
        private readonly ReportStateMachine $reportStates,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** The version being worked on or last released; null before any test arrived. */
    public function current(string $organizationId, string $orderId, string $labId): ?Report
    {
        return $this->inOrganization($organizationId, fn (): ?Report => Report::query()
            ->where('order_id', $orderId)
            ->where('processing_branch_id', $labId)
            ->where('status', '!=', ReportStatus::Amended)
            ->first());
    }

    /**
     * New tests reached the lab: the report exists and is open for them. A
     * released report is superseded by a new version that will include them.
     */
    public function openForNewTests(string $organizationId, string $orderId, string $labId): Report
    {
        return DB::transaction(function () use ($organizationId, $orderId, $labId): Report {
            $report = $this->current($organizationId, $orderId, $labId);

            $report = match (true) {
                $report === null => $this->create($organizationId, $orderId, $labId, 1, null),
                $report->status === ReportStatus::Released => $this->supersede($report, self::ADDED_TESTS_REASON),
                default => $report,
            };

            $this->refresh($report);

            return $report;
        });
    }

    /** A released report is corrected: the next version starts and this one is kept as `amended`. */
    public function supersede(Report $released, string $reason): Report
    {
        return DB::transaction(function () use ($released, $reason): Report {
            $this->reportStates->transition($released, ReportStatus::Amended);
            $next = $this->create($released->organization_id, $released->order_id, $released->processing_branch_id, $released->version + 1, $reason);
            $this->refresh($next);

            return $next;
        });
    }

    /** Re-derives an unreleased report's status from its tests and signatures. */
    public function refresh(Report $report): void
    {
        if (! in_array($report->status, self::OPEN, true)) {
            return;
        }

        $target = ReportReadiness::statusFor(
            $this->testStatusesByDepartment($report),
            $report->signatures()->pluck('department_id')->all(),
        );

        // A withheld report stays withheld while its content is unchanged.
        if ($report->status === ReportStatus::Withheld && $target === ReportStatus::Signed) {
            return;
        }

        $this->moveTo($report, $target);
    }

    /**
     * A department's results changed before release: its signature no longer
     * covers them.
     *
     * @param  list<string>  $departmentIds
     */
    public function revokeSignatures(Report $report, array $departmentIds, string $reason): void
    {
        $signatures = $report->signatures()->whereIn('department_id', $departmentIds)->get();

        foreach ($signatures as $signature) {
            $signature->update(['revoked_at' => CarbonImmutable::now()]);
            $this->auditLogger->record('report.signature_revoked', $report, [], [
                'department_id' => $signature->department_id,
                'signatory_id' => $signature->signatory_id,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * Live tests on the report, grouped by department.
     *
     * @return array<string, list<WorklistStatus>>
     */
    public function testStatusesByDepartment(Report $report): array
    {
        $statuses = [];

        foreach ($this->liveEntries($report) as $entry) {
            $statuses[$entry->department_id][] = $entry->status;
        }

        return $statuses;
    }

    /** @return Collection<int, WorklistEntry> */
    public function liveEntries(Report $report): Collection
    {
        return $this->inOrganization($report->organization_id, fn () => WorklistEntry::query()
            ->where('order_id', $report->order_id)
            ->where('processing_branch_id', $report->processing_branch_id)
            ->where('status', '!=', WorklistStatus::Withdrawn)
            ->orderBy('id')
            ->get());
    }

    private function create(string $organizationId, string $orderId, string $labId, int $version, ?string $amendmentReason): Report
    {
        $facts = $this->orders->reportFacts($organizationId, $orderId);

        $report = new Report([
            'version' => $version,
            'status' => ReportStatus::Draft,
            'amendment_reason' => $amendmentReason,
        ]);
        $report->forceFill([
            'organization_id' => $organizationId,
            'order_id' => $orderId,
            'patient_id' => $facts->patientId,
            'branch_id' => $facts->branchId,
            'franchise_id' => $facts->franchiseId,
            'b2b_client_id' => $facts->b2bClientId,
            'processing_branch_id' => $labId,
            'qr_code' => bin2hex(random_bytes(20)),
        ])->save();
        $this->auditLogger->recordCreated('report.create', $report);

        return $report;
    }

    /** Draft, waiting for signatures and signed are reached through each other in that order. */
    private function moveTo(Report $report, ReportStatus $target): void
    {
        if ($report->status === $target) {
            return;
        }

        if ($this->reportStates->canTransition($report->status, $target)) {
            $this->reportStates->transition($report, $target);

            return;
        }

        $next = $report->status === ReportStatus::Draft ? ReportStatus::PendingSignature : ReportStatus::Draft;
        $this->reportStates->transition($report, $next);
        $this->moveTo($report, $target);
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
