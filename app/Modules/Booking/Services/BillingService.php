<?php

namespace App\Modules\Booking\Services;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Contracts\GatewayEvent;
use App\Modules\Booking\Contracts\PaymentGateway;
use App\Modules\Booking\Domain\InvoiceStatusRule;
use App\Modules\Booking\Domain\PaymentRule;
use App\Modules\Booking\Enums\BillToType;
use App\Modules\Booking\Enums\InvoicePaymentStatus;
use App\Modules\Booking\Enums\OrderStatus;
use App\Modules\Booking\Enums\PaymentMode;
use App\Modules\Booking\Enums\PaymentStatus;
use App\Modules\Booking\Enums\RefundStatus;
use App\Modules\Booking\Errors\BookingError;
use App\Modules\Booking\Models\Invoice;
use App\Modules\Booking\Models\Order;
use App\Modules\Booking\Models\Payment;
use App\Modules\Booking\Models\Refund;
use App\Modules\Booking\StateMachines\OrderStateMachine;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Context\Actor;
use App\Modules\Shared\Context\CurrentActor;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Numbering\FinancialYear;
use App\Modules\Shared\Numbering\NumberSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Invoices, payments and refunds (spec §5.2, §7.5). Every money change locks
 * the invoice row first, so parallel payments cannot both pass the "balance
 * due" check. Payment status is always recomputed from the amounts.
 */
final class BillingService
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    private const DEFAULT_INVOICE_FORMAT = 'INV/{branch_code}/{FY}/{seq:5}';

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NetworkDirectory $network,
        private readonly NumberSequenceService $sequences,
        private readonly OrderStateMachine $orders,
        private readonly PaymentGateway $gateway,
        private readonly CurrentActor $currentActor,
    ) {}

    /** Creates the invoice for an order (or a supplementary one for add-on tests). */
    public function createInvoice(Order $order, Money $gross, Money $discount): Invoice
    {
        $now = CarbonImmutable::now();
        $today = $now->setTimezone(self::BUSINESS_TIMEZONE)->startOfDay();
        $billedToClient = $order->b2b_client_id !== null;
        $branchCode = $this->network->branchCode($order->branch_id);
        $format = (string) ($this->network->organizationSettings($order->organization_id)['invoice_number_format'] ?? self::DEFAULT_INVOICE_FORMAT);

        $invoice = new Invoice([
            'order_id' => $order->id,
            'billed_by_branch_id' => $order->branch_id,
            'franchise_id' => $order->franchise_id,
            'bill_to_type' => $billedToClient ? BillToType::B2bClient : BillToType::Patient,
            'b2b_client_id' => $order->b2b_client_id,
            'invoice_date' => $today->toDateString(),
            'due_date' => $billedToClient ? $today->addDays($this->network->b2bClientCreditDays((string) $order->b2b_client_id))->toDateString() : null,
            'amount' => $gross,
            'discount' => $discount,
            'tax' => Money::zero(),
            'total' => $gross->subtract($discount),
            'amount_paid' => Money::zero(),
            'payment_status' => $billedToClient ? InvoicePaymentStatus::Credit : InvoicePaymentStatus::Unpaid,
        ]);
        $invoice->organization_id = $order->organization_id;
        $invoice->invoice_no = $this->sequences->nextFormatted(
            $order->organization_id,
            "invoice:{$order->branch_id}",
            $format,
            ['branch_code' => $branchCode],
            FinancialYear::containing($now),
        );
        $invoice->save();
        $this->auditLogger->recordCreated('invoice.create', $invoice);

        return $invoice;
    }

    /** A payment taken at the desk or by a phlebotomist (cash, card machine, UPI QR). */
    public function recordDeskPayment(StaffContext $staff, Invoice $invoice, PaymentMode $mode, Money $amount, ?string $transactionId): Payment
    {
        return DB::transaction(function () use ($staff, $invoice, $mode, $amount, $transactionId): Payment {
            $invoice = $this->lockInvoice($invoice->id);
            $this->assertPayable($invoice, $amount);

            $payment = new Payment([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'mode' => $mode,
                'gateway' => null,
                'transaction_id' => $transactionId,
                'received_by' => $staff->user()->id,
                'paid_at' => now(),
                'status' => PaymentStatus::Success,
            ]);
            $payment->save();
            $this->auditLogger->recordCreated('payment.create', $payment);
            $this->refresh($invoice);

            return $payment;
        });
    }

    /**
     * Records a payment confirmed by the gateway webhook. Safe to call many
     * times for the same payment: (gateway, transaction_id) is unique.
     */
    public function recordGatewayPayment(GatewayEvent $event): Payment
    {
        return DB::transaction(function () use ($event): Payment {
            $existing = Payment::query()->where('gateway', $this->gateway->name())->where('transaction_id', $event->paymentId)->first();

            if ($existing !== null) {
                return $existing;
            }

            $invoice = $this->lockInvoice((string) $event->invoiceId);

            return $this->asSystemOf($invoice, fn (): Payment => $this->saveGatewayPayment($invoice, $event));
        });
    }

    private function saveGatewayPayment(Invoice $invoice, GatewayEvent $event): Payment
    {
        $amount = $event->amount ?? Money::zero();

        $payment = new Payment([
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'mode' => $event->mode,
            'gateway' => $this->gateway->name(),
            'transaction_id' => $event->paymentId,
            'received_by' => null,
            'paid_at' => $event->occurredAt ?? now(),
            'status' => PaymentStatus::Success,
        ]);
        $payment->save();
        $this->auditLogger->recordCreated('payment.create', $payment);

        // The money has arrived whatever our records say; an overpayment
        // is kept and refunded by staff rather than lost.
        $this->refresh($invoice, allowOverpayment: true);

        return $payment;
    }

    /**
     * Money a B2B client paid against its settlement, applied to one of its
     * credit invoices. The bank transfer reference (UTR) is the transaction ID.
     */
    public function recordClientSettlementPayment(Invoice $invoice, Money $amount, string $reference): Payment
    {
        return DB::transaction(function () use ($invoice, $amount, $reference): Payment {
            $invoice = $this->lockInvoice($invoice->id);

            if ($amount->isGreaterThan($invoice->balanceDue())) {
                throw BookingError::overpayment($invoice->balanceDue());
            }

            $payment = new Payment([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'mode' => PaymentMode::Netbanking,
                'gateway' => null,
                'transaction_id' => $reference,
                'received_by' => $this->currentActor->userId(),
                'paid_at' => now(),
                'status' => PaymentStatus::Success,
            ]);
            $payment->save();
            $this->auditLogger->recordCreated('payment.create', $payment);
            $this->refresh($invoice);

            return $payment;
        });
    }

    /** Refunds part or all of a payment (spec §7.5 refunds). */
    public function refund(StaffContext $approver, Payment $payment, Money $amount, string $reason): Refund
    {
        return DB::transaction(function () use ($approver, $payment, $amount, $reason): Refund {
            $invoice = $this->lockInvoice($payment->invoice_id);
            $payment->load('refunds');

            if ($amount->isGreaterThan($payment->refundableAmount())) {
                throw BookingError::refundExceedsPayment($payment->refundableAmount());
            }

            $gatewayRefund = $payment->gateway !== null && $payment->transaction_id !== null
                ? $this->gateway->refund($payment->transaction_id, $amount, $reason)
                : null;

            $refund = new Refund([
                'payment_id' => $payment->id,
                'amount' => $amount,
                'reason' => $reason,
                'gateway_refund_id' => $gatewayRefund?->refundId,
                'approved_by' => $approver->user()->id,
                // Cash and desk refunds are handed back on the spot.
                'status' => $gatewayRefund === null || $gatewayRefund->isProcessed ? RefundStatus::Processed : RefundStatus::Requested,
            ]);
            $refund->save();
            $this->auditLogger->recordCreated('refund.create', $refund);
            $this->refresh($invoice);

            return $refund;
        });
    }

    /** Gateway confirmed a refund that was still processing. Idempotent. */
    public function markRefundProcessed(string $gatewayRefundId): void
    {
        DB::transaction(function () use ($gatewayRefundId): void {
            $refund = Refund::query()->where('gateway_refund_id', $gatewayRefundId)->first();

            if ($refund === null || $refund->status === RefundStatus::Processed) {
                return;
            }

            $invoice = $this->lockInvoice(Payment::query()->findOrFail($refund->payment_id)->invoice_id);

            $this->asSystemOf($invoice, function () use ($refund, $invoice): void {
                $refund->update(['status' => RefundStatus::Processed]);
                $this->auditLogger->recordChanges('refund.update', $refund);
                $this->refresh($invoice);
            });
        });
    }

    /**
     * Recomputes amount paid and payment status, then confirms the order if
     * its payment rule is now met (spec §5.2).
     */
    public function refresh(Invoice $invoice, bool $allowOverpayment = false): void
    {
        $payments = Payment::query()->with('refunds')->where('invoice_id', $invoice->id)->where('status', PaymentStatus::Success)->get();
        $received = $payments->reduce(fn (Money $sum, Payment $payment) => $sum->add($payment->amount), Money::zero());
        $refunds = $payments->flatMap(fn (Payment $payment) => $payment->refunds)->reject(fn (Refund $refund) => $refund->status === RefundStatus::Failed);
        $refunded = $refunds->reduce(fn (Money $sum, Refund $refund) => $sum->add($refund->amount), Money::zero());
        $netPaid = $received->subtract($refunded);

        if ($allowOverpayment && $netPaid->isGreaterThan($invoice->total)) {
            $netPaid = $invoice->total;
        }

        $invoice->amount_paid = $netPaid;
        $invoice->payment_status = InvoiceStatusRule::statusFor($invoice->total, $netPaid, $refunds->isNotEmpty(), $invoice->bill_to_type === BillToType::B2bClient);
        $invoice->save();
        $this->auditLogger->recordChanges('invoice.payment_update', $invoice);

        $this->confirmOrderIfPaymentRuleMet($invoice);
    }

    private function confirmOrderIfPaymentRuleMet(Invoice $invoice): void
    {
        $order = Order::query()->findOrFail($invoice->order_id);

        if ($order->status !== OrderStatus::Draft) {
            return;
        }

        $advancePercent = (string) ($this->network->organizationSettings($order->organization_id)['walk_in_advance_percent']
            ?? config('pathology.booking.walk_in_advance_percent'));

        if (PaymentRule::isMet($order->order_source, $order->b2b_client_id !== null, $invoice->total, $invoice->amount_paid, $advancePercent)) {
            $this->orders->transition($order, OrderStatus::Confirmed);
        }
    }

    /**
     * Gateway callbacks arrive with no signed-in user and no organization;
     * once the invoice is known, the work is done as the system of its
     * organization, so audit rows land in the right place.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asSystemOf(Invoice $invoice, callable $callback): mixed
    {
        if ($this->currentActor->organizationId() !== null) {
            return $callback();
        }

        return $this->currentActor->runAs(Actor::system($invoice->organization_id), $callback);
    }

    private function assertPayable(Invoice $invoice, Money $amount): void
    {
        if (! in_array($invoice->payment_status, [InvoicePaymentStatus::Unpaid, InvoicePaymentStatus::PartiallyPaid], true)) {
            throw BookingError::invoiceNotPayable();
        }

        if ($amount->isGreaterThan($invoice->balanceDue())) {
            throw BookingError::overpayment($invoice->balanceDue());
        }
    }

    private function lockInvoice(string $invoiceId): Invoice
    {
        return Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
    }
}
