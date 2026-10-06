<?php

namespace App\Modules\Lab\Services;

use App\Modules\Lab\Enums\ReportStatus;
use App\Modules\Lab\Errors\LabError;
use App\Modules\Lab\Models\Report;
use Illuminate\Support\Facades\DB;

/**
 * Corrections after release (spec §5.5 step 6): the next version starts
 * with a reason, the released one is kept as `amended` with its PDF. The lab
 * then reruns or re-verifies what changed, and the new version is signed and
 * released like the first.
 */
final class ReportAmendmentService
{
    public function __construct(
        private readonly LabGuard $labGuard,
        private readonly ReportAssembly $reports,
    ) {}

    /** @return Report the new version */
    public function amend(Report $report, string $reason): Report
    {
        $this->labGuard->assertActsFor($report->processing_branch_id, 'amend reports');

        return DB::transaction(function () use ($report, $reason): Report {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);

            if ($report->status !== ReportStatus::Released) {
                throw LabError::reportNotAmendable();
            }

            return $this->reports->supersede($report, $reason);
        });
    }
}
