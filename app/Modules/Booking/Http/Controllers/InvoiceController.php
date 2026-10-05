<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Http\Requests\DeskPaymentRequest;
use App\Modules\Booking\Http\Requests\PaymentLinkRequest;
use App\Modules\Booking\Http\Requests\RefundRequest;
use App\Modules\Booking\Http\Resources\InvoiceResource;
use App\Modules\Booking\Http\Resources\PaymentResource;
use App\Modules\Booking\Http\Resources\RefundResource;
use App\Modules\Booking\Models\Invoice;
use App\Modules\Booking\Models\Payment;
use App\Modules\Booking\Services\BillingService;
use App\Modules\Booking\Services\PaymentLinkService;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Invoices, desk payments, refunds and payment links (spec §8 Billing). */
final class InvoiceController
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly StaffContext $staff,
    ) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'payment_status' => 'payment_status',
                'order_id' => 'order_id',
                'bill_to_type' => 'bill_to_type',
                'b2b_client_id' => 'b2b_client_id',
                'invoice_date' => 'invoice_date',
            ])
            ->allowSearch(fn ($query, string $text) => $query->where('invoice_no', $text))
            ->allowSorts(['invoice_date'])
            ->apply(Invoice::query());

        return CursorPage::respond($query, $request, InvoiceResource::class);
    }

    public function show(Invoice $invoice): Response
    {
        return InvoiceResource::make($invoice->load('payments.refunds'))->response();
    }

    public function pay(DeskPaymentRequest $request, Invoice $invoice): Response
    {
        $payment = $request->payment();
        $recorded = $this->billing->recordDeskPayment($this->staff, $invoice, $payment->mode, $payment->amount, $payment->transactionId);

        return ApiResponse::created(PaymentResource::make($recorded), "/api/v1/invoices/{$invoice->id}");
    }

    /** POST /payments/{payment}/refunds. The payment is reached through a visible invoice. */
    public function refund(RefundRequest $request, string $paymentId): Response
    {
        $payment = Payment::query()
            ->whereKey($paymentId)
            ->whereIn('invoice_id', Invoice::query()->select('id'))
            ->firstOrFail();

        $refund = $this->billing->refund($this->staff, $payment, $request->amount(), $request->validated('reason'));

        return ApiResponse::created(RefundResource::make($refund), "/api/v1/invoices/{$payment->invoice_id}");
    }

    /** POST /payment-links: a gateway link for the balance, sent to the patient. */
    public function paymentLink(PaymentLinkRequest $request, PaymentLinkService $links): JsonResponse
    {
        $invoice = Invoice::query()->find($request->validated('invoice_id'))
            ?? throw ValidationException::withMessages(['invoice_id' => 'The selected invoice does not exist.']);

        $link = $links->createAndSend($invoice);

        return new JsonResponse(['data' => [
            'invoice_id' => $invoice->id,
            'link_id' => $link->linkId,
            'url' => $link->url,
            'amount' => $invoice->balanceDue(),
            'expires_at' => $link->expiresAt->toIso8601ZuluString(),
        ]]);
    }
}
