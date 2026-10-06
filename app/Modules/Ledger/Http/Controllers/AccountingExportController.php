<?php

namespace App\Modules\Ledger\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Ledger\Enums\AccountingExportFormat;
use App\Modules\Ledger\Enums\AccountingExportKind;
use App\Modules\Ledger\Http\Requests\AccountingExportRequest;
use App\Modules\Ledger\Http\Resources\AccountingExportResource;
use App\Modules\Ledger\Models\AccountingExport;
use App\Modules\Ledger\Services\AccountingExports;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Monthly sales and partner-ledger journals for Tally and Zoho Books (spec §3). */
final class AccountingExportController
{
    public function __construct(private readonly AccountingExports $exports) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters(['kind' => 'kind', 'format' => 'format', 'status' => 'status'])
            ->allowSorts(['period_start', 'created_at'])
            ->apply(AccountingExport::query());

        return CursorPage::respond($query, $request, AccountingExportResource::class);
    }

    /** 202: the file is built in the background; poll the export or its file. */
    public function store(AccountingExportRequest $request, StaffContext $staff): Response
    {
        $export = $this->exports->request(
            $staff->user()->organization_id,
            AccountingExportKind::from($request->validated('kind')),
            AccountingExportFormat::from($request->validated('format')),
            CarbonImmutable::parse($request->validated('month').'-01', 'Asia/Kolkata'),
        );

        return ApiResponse::accepted(AccountingExportResource::make($export))
            ->header('Location', "/api/v1/accounting-exports/{$export->id}");
    }

    public function show(AccountingExport $accountingExport): Response
    {
        return AccountingExportResource::make($accountingExport)->response();
    }

    /** The file; 202 while it is still being built. */
    public function file(AccountingExport $accountingExport): Response
    {
        return $this->exports->download($accountingExport)
            ?? ApiResponse::accepted(AccountingExportResource::make($accountingExport))->header('Retry-After', '30');
    }
}
