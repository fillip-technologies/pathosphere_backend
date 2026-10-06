<?php

namespace App\Modules\Ledger\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Ledger\Http\Requests\DisputeSettlementRequest;
use App\Modules\Ledger\Http\Requests\MarkSettledRequest;
use App\Modules\Ledger\Http\Resources\SettlementResource;
use App\Modules\Ledger\Models\Settlement;
use App\Modules\Ledger\Services\SettlementService;
use App\Modules\Ledger\Services\SettlementStatements;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Settlements and their statements (spec §5.6, §8 Settlements). */
final class SettlementController
{
    public function __construct(private readonly SettlementService $settlements) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'franchise_id' => 'franchise_id',
                'b2b_client_id' => 'b2b_client_id',
                'status' => 'status',
                'direction' => 'direction',
            ])
            ->allowSorts(['period_end', 'created_at'])
            ->apply(Settlement::query());

        return CursorPage::respond($query, $request, SettlementResource::class);
    }

    public function show(Settlement $settlement): Response
    {
        return EntityTag::attach(SettlementResource::make($settlement->load('entries'))->response(), $settlement);
    }

    public function approve(StaffContext $staff, Settlement $settlement): Response
    {
        return $this->respond($this->settlements->approve($staff, $settlement));
    }

    public function dispute(DisputeSettlementRequest $request, Settlement $settlement): Response
    {
        return $this->respond($this->settlements->dispute($settlement, $request->validated('note')));
    }

    public function markSettled(MarkSettledRequest $request, Settlement $settlement): Response
    {
        return $this->respond($this->settlements->markSettled($settlement, $request->validated('payment_reference')));
    }

    /** The PDF; 202 while it is still being rendered. */
    public function statement(Settlement $settlement, SettlementStatements $statements): Response
    {
        return $statements->download($settlement)
            ?? ApiResponse::accepted(SettlementResource::make($settlement))->header('Retry-After', '30');
    }

    private function respond(Settlement $settlement): Response
    {
        return EntityTag::attach(SettlementResource::make($settlement->load('entries'))->response(), $settlement);
    }
}
