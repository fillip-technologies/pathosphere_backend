<?php

namespace App\Modules\Samples\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Samples\Http\Requests\CancelStockTransferRequest;
use App\Modules\Samples\Http\Requests\DispatchStockRequest;
use App\Modules\Samples\Http\Requests\StockTransferRequest;
use App\Modules\Samples\Http\Resources\StockTransferResource;
use App\Modules\Samples\Models\StockTransfer;
use App\Modules\Samples\Services\StockTransferService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use App\Modules\Shared\Money\Money;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Stock moving between branches (spec §8 Inventory). Transfers are cancelled, never deleted. */
final class StockTransferController
{
    public function __construct(private readonly StockTransferService $transfers) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'from_branch_id' => 'from_branch_id',
                'to_branch_id' => 'to_branch_id',
                'status' => 'status',
                'item_code' => 'item_code',
            ])
            ->allowSorts(['created_at'])
            ->allowSearch(fn ($query, string $text) => $query->where('transfer_no', 'like', "{$text}%"))
            ->apply(StockTransfer::query());

        return CursorPage::respond($query, $request, StockTransferResource::class);
    }

    public function store(StockTransferRequest $request, StaffContext $staff): Response
    {
        $transfer = $this->transfers->request($staff->user()->organization_id, $request->validated());

        return ApiResponse::created(StockTransferResource::make($transfer), "/api/v1/stock-transfers/{$transfer->id}");
    }

    public function show(StockTransfer $stockTransfer): Response
    {
        return EntityTag::attach(StockTransferResource::make($stockTransfer)->response(), $stockTransfer);
    }

    public function update(StockTransferRequest $request, StockTransfer $stockTransfer): Response
    {
        EntityTag::assertIfMatch($request, $stockTransfer);

        return $this->respond($this->transfers->update($stockTransfer, $request->validated()));
    }

    public function dispatch(DispatchStockRequest $request, StockTransfer $stockTransfer): Response
    {
        $charge = $request->has('charge_amount') ? Money::fromString((string) $request->validated('charge_amount')) : null;

        return $this->respond($this->transfers->dispatch($stockTransfer, $charge));
    }

    public function receive(StockTransfer $stockTransfer): Response
    {
        return $this->respond($this->transfers->receive($stockTransfer));
    }

    public function cancel(CancelStockTransferRequest $request, StockTransfer $stockTransfer): Response
    {
        return $this->respond($this->transfers->cancel($stockTransfer, $request->validated('reason')));
    }

    private function respond(StockTransfer $transfer): Response
    {
        return EntityTag::attach(StockTransferResource::make($transfer)->response(), $transfer);
    }
}
