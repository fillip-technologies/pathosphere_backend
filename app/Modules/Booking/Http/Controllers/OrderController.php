<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Http\Requests\AddOrderItemsRequest;
use App\Modules\Booking\Http\Requests\BookOrderRequest;
use App\Modules\Booking\Http\Requests\CancelOrderRequest;
use App\Modules\Booking\Http\Resources\InvoiceResource;
use App\Modules\Booking\Http\Resources\OrderResource;
use App\Modules\Booking\Models\Order;
use App\Modules\Booking\Services\OrderBookingService;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrderController
{
    private const DETAIL_RELATIONS = ['items', 'invoices.payments.refunds', 'homeCollection'];

    public function __construct(
        private readonly OrderBookingService $booking,
        private readonly StaffContext $staff,
    ) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'status' => 'status',
                'patient_id' => 'patient_id',
                'branch_id' => 'branch_id',
                'order_source' => 'order_source',
                'b2b_client_id' => 'b2b_client_id',
                'order_date' => fn ($query, string $date) => $query->whereDate('order_date', $date),
            ])
            ->allowSearch(fn ($query, string $text) => $query->where('order_no', $text))
            ->allowSorts(['order_date'])
            ->apply(Order::query());

        return CursorPage::respond($query, $request, OrderResource::class);
    }

    /** POST /orders: retry-safe with an Idempotency-Key (spec §8.5). */
    public function store(BookOrderRequest $request): Response
    {
        $order = $this->booking->book($this->staff, $request->toCommand());

        return ApiResponse::created(OrderResource::make($order->load(self::DETAIL_RELATIONS)), "/api/v1/orders/{$order->id}");
    }

    public function show(Order $order): Response
    {
        return OrderResource::make($order->load(self::DETAIL_RELATIONS))->response();
    }

    public function cancel(CancelOrderRequest $request, Order $order): Response
    {
        $order = $this->booking->cancel($this->staff, $order, $request->validated('reason'));

        return OrderResource::make($order->load(self::DETAIL_RELATIONS))->response();
    }

    /** Add-on tests: answers with the supplementary invoice. */
    public function addItems(AddOrderItemsRequest $request, Order $order): Response
    {
        $invoice = $this->booking->addItems(
            $this->staff,
            $order,
            $request->quoteItems(),
            $request->discountAmount(),
            $request->discountReason(),
            $request->deskPayment(),
        );

        return ApiResponse::created(InvoiceResource::make($invoice->load('payments')), "/api/v1/invoices/{$invoice->id}");
    }
}
