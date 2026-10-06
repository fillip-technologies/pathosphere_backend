<?php

namespace App\Modules\Lab\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Lab\Enums\ReportStatus;
use App\Modules\Lab\Http\Requests\AmendReportRequest;
use App\Modules\Lab\Http\Requests\SignReportRequest;
use App\Modules\Lab\Http\Resources\ReportDetailResource;
use App\Modules\Lab\Http\Resources\ReportResource;
use App\Modules\Lab\Models\Report;
use App\Modules\Lab\Services\ReportAmendmentService;
use App\Modules\Lab\Services\ReportDetails;
use App\Modules\Lab\Services\ReportFiles;
use App\Modules\Lab\Services\ReportReleaseService;
use App\Modules\Lab\Services\ReportSigningService;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Reports: signing, release, amendment and the stored PDF (spec §5.5, §8 Reports). */
final class ReportController
{
    public function __construct(
        private readonly ReportDetails $details,
        private readonly StaffContext $staff,
    ) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'status' => function ($query, string $status): void {
                    if (ReportStatus::tryFrom($status) === null) {
                        throw ValidationException::withMessages(['filter.status' => 'Choose one of: '.implode(', ', array_column(ReportStatus::cases(), 'value')).'.']);
                    }

                    $query->where('status', $status);
                },
                'order_id' => 'order_id',
                'patient_id' => 'patient_id',
                'branch_id' => 'branch_id',
                'processing_branch_id' => 'processing_branch_id',
                'b2b_client_id' => 'b2b_client_id',
            ])
            ->allowSorts(['created_at', 'released_at'])
            ->apply(Report::query()->with('signatures'));

        return CursorPage::respond($query, $request, ReportResource::class);
    }

    public function show(Report $report): Response
    {
        return $this->detail($report);
    }

    public function sign(SignReportRequest $request, Report $report, ReportSigningService $signing): Response
    {
        return $this->detail($signing->sign($this->staff, $report, $request->departmentIds(), $request->validated('code'), $request->ip()));
    }

    /** Releases a signed report, or withholds it for a client with overdue dues (status `withheld`). */
    public function release(Report $report, ReportReleaseService $releases): Response
    {
        return $this->detail($releases->release($this->staff, $report));
    }

    /** Starts the next version; answers with it. */
    public function amend(AmendReportRequest $request, Report $report, ReportAmendmentService $amendments): Response
    {
        $next = $amendments->amend($report, $request->validated('reason'));

        return ApiResponse::created(ReportDetailResource::make($this->details->describe($next->load('signatures'))), "/api/v1/reports/{$next->id}");
    }

    public function pdf(Report $report, ReportFiles $files): Response
    {
        return $files->download($report, 'staff');
    }

    private function detail(Report $report): Response
    {
        return ReportDetailResource::make($this->details->describe($report->refresh()->load('signatures')))->response();
    }
}
