<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\BillToType;
use App\Modules\Booking\Enums\InvoicePaymentStatus;
use App\Modules\Booking\Enums\OrderStatus;
use App\Modules\Booking\Enums\PaymentStatus;
use App\Modules\Booking\Enums\RefundStatus;
use App\Modules\Booking\Models\Invoice;
use App\Modules\Booking\Models\Payment;
use App\Modules\Booking\Models\Refund;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;

/**
 * Billing facts the ledger needs for settlements and dues (spec §5.6,
 * §9 B2B dues reminder). Read at organization level: these are run by
 * settlement jobs and finance, never shown to branch staff.
 */
final class PartnerBillingFacts
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public function __construct(private readonly CurrentScope $currentScope) {}

    /** Patient billing at a franchise's branches between two business dates, inclusive. */
    public function franchisePeriod(string $organizationId, string $franchiseId, CarbonImmutable $fromDate, CarbonImmutable $toDate): PartnerBilling
    {
        [$fromUtc, $untilUtc] = $this->utcBounds($fromDate, $toDate);

        return $this->currentScope->runAs(ScopeContext::system($organizationId), function () use ($franchiseId, $fromDate, $toDate, $fromUtc, $untilUtc): PartnerBilling {
            $patientInvoices = fn () => Invoice::query()->where('franchise_id', $franchiseId)->where('bill_to_type', BillToType::Patient);

            $netBilled = $patientInvoices()
                ->whereBetween('invoice_date', [$fromDate->toDateString(), $toDate->toDateString()])
                ->whereHas('order', fn ($orders) => $orders->where('status', '!=', OrderStatus::Cancelled))
                ->get(['total'])
                ->reduce(fn (Money $sum, Invoice $invoice) => $sum->add($invoice->total), Money::zero());

            // Online payments land in HQ's gateway account, so only desk money is the franchise's to hand over.
            $deskPayments = fn () => Payment::query()
                ->whereNull('gateway')
                ->whereIn('invoice_id', $patientInvoices()->select('id'));

            $collected = $deskPayments()
                ->where('status', PaymentStatus::Success)
                ->where('paid_at', '>=', $fromUtc)
                ->where('paid_at', '<', $untilUtc)
                ->get(['amount'])
                ->reduce(fn (Money $sum, Payment $payment) => $sum->add($payment->amount), Money::zero());

            $refunded = Refund::query()
                ->whereIn('payment_id', $deskPayments()->select('id'))
                ->where('status', '!=', RefundStatus::Failed)
                ->where('created_at', '>=', $fromUtc)
                ->where('created_at', '<', $untilUtc)
                ->get(['amount'])
                ->reduce(fn (Money $sum, Refund $refund) => $sum->add($refund->amount), Money::zero());

            return new PartnerBilling($netBilled, $collected->subtract($refunded));
        });
    }

    /**
     * B2B clients with invoices past their due date and not fully paid,
     * ignoring cancelled orders.
     *
     * @return list<B2bDues>
     */
    public function overdueB2bInvoices(string $organizationId, CarbonImmutable $today): array
    {
        return $this->currentScope->runAs(ScopeContext::system($organizationId), function () use ($today): array {
            $invoices = Invoice::query()
                ->where('bill_to_type', BillToType::B2bClient)
                ->whereIn('payment_status', [InvoicePaymentStatus::Credit, InvoicePaymentStatus::PartiallyPaid])
                ->where('due_date', '<', $today->toDateString())
                ->whereHas('order', fn ($orders) => $orders->where('status', '!=', OrderStatus::Cancelled))
                ->get(['b2b_client_id', 'total', 'amount_paid', 'due_date']);

            $dues = [];
            foreach ($invoices->groupBy('b2b_client_id') as $clientId => $clientInvoices) {
                $amountDue = $clientInvoices->reduce(fn (Money $sum, Invoice $invoice) => $sum->add($invoice->balanceDue()), Money::zero());

                if ($amountDue->isGreaterThan(Money::zero())) {
                    /** @var CarbonImmutable $oldest */
                    $oldest = $clientInvoices->min('due_date');
                    $dues[] = new B2bDues((string) $clientId, $clientInvoices->count(), $amountDue, $oldest);
                }
            }

            return $dues;
        });
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function utcBounds(CarbonImmutable $fromDate, CarbonImmutable $toDate): array
    {
        return [
            CarbonImmutable::parse($fromDate->toDateString(), self::BUSINESS_TIMEZONE)->utc(),
            CarbonImmutable::parse($toDate->toDateString(), self::BUSINESS_TIMEZONE)->addDay()->utc(),
        ];
    }
}
