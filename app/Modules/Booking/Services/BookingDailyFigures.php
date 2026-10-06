<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\OrderItemStatus;
use App\Modules\Booking\Enums\OrderStatus;
use App\Modules\Booking\Enums\PaymentStatus;
use App\Modules\Booking\Enums\RefundStatus;
use App\Modules\Booking\Models\Invoice;
use App\Modules\Booking\Models\Order;
use App\Modules\Booking\Models\OrderItem;
use App\Modules\Booking\Models\Payment;
use App\Modules\Booking\Models\Refund;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;

/**
 * Booking and billing totals per branch for one business day (spec §11
 * observability 4: dashboards read nightly summaries, never live tables).
 */
final class BookingDailyFigures
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public function __construct(private readonly CurrentScope $currentScope) {}

    /** @return array<string, BranchBookingDay> by branch ID */
    public function forDay(string $organizationId, CarbonImmutable $date): array
    {
        $fromUtc = CarbonImmutable::parse($date->toDateString(), self::BUSINESS_TIMEZONE)->utc();
        $untilUtc = $fromUtc->addDay();

        return $this->currentScope->runAs(ScopeContext::system($organizationId), function () use ($date, $fromUtc, $untilUtc): array {
            $days = [];
            $day = function (string $branchId) use (&$days): BranchBookingDay {
                return $days[$branchId] ??= new BranchBookingDay;
            };

            $orders = Order::query()->where('order_date', '>=', $fromUtc)->where('order_date', '<', $untilUtc)->get(['id', 'branch_id', 'status']);
            foreach ($orders as $order) {
                $day($order->branch_id)->ordersBooked++;
                if ($order->status === OrderStatus::Cancelled) {
                    $day($order->branch_id)->ordersCancelled++;
                }
            }

            $branchByOrder = $orders->pluck('branch_id', 'id');
            OrderItem::query()
                ->whereIn('order_id', $branchByOrder->keys())
                ->whereNotNull('test_id')
                ->where('status', '!=', OrderItemStatus::Cancelled)
                ->get(['order_id'])
                ->each(function (OrderItem $item) use ($day, $branchByOrder): void {
                    $day((string) $branchByOrder[$item->order_id])->testsOrdered++;
                });

            Invoice::query()
                ->where('invoice_date', $date->toDateString())
                ->whereHas('order', fn ($query) => $query->where('status', '!=', OrderStatus::Cancelled))
                ->get(['billed_by_branch_id', 'total'])
                ->each(function (Invoice $invoice) use ($day): void {
                    $branch = $day($invoice->billed_by_branch_id);
                    $branch->grossBilling = $branch->grossBilling?->add($invoice->total);
                });

            $branchByInvoice = fn (array $invoiceIds) => Invoice::query()->whereKey($invoiceIds)->pluck('billed_by_branch_id', 'id');

            $payments = Payment::query()->where('status', PaymentStatus::Success)->where('paid_at', '>=', $fromUtc)->where('paid_at', '<', $untilUtc)->get(['invoice_id', 'amount']);
            $paymentBranches = $branchByInvoice($payments->pluck('invoice_id')->unique()->values()->all());
            foreach ($payments as $payment) {
                $branch = $day((string) $paymentBranches[$payment->invoice_id]);
                $branch->collected = $branch->collected?->add($payment->amount);
            }

            $refunds = Refund::query()->where('status', '!=', RefundStatus::Failed)->where('created_at', '>=', $fromUtc)->where('created_at', '<', $untilUtc)->get(['payment_id', 'amount']);
            $invoiceByPayment = Payment::query()->whereKey($refunds->pluck('payment_id')->unique()->values()->all())->pluck('invoice_id', 'id');
            $refundBranches = $branchByInvoice($invoiceByPayment->unique()->values()->all());
            foreach ($refunds as $refund) {
                $branch = $day((string) $refundBranches[$invoiceByPayment[$refund->payment_id]]);
                $branch->collected = $branch->collected?->subtract($refund->amount);
            }

            return $days;
        });
    }
}
