<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\BillToType;
use App\Modules\Booking\Enums\InvoicePaymentStatus;
use App\Modules\Booking\Enums\OrderStatus;
use App\Modules\Booking\Models\Invoice;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;

/**
 * A B2B client's invoices are its receivables; its ledger is the account
 * they settle through. When the client pays a settlement, the money is
 * applied to its unpaid invoices of that period, oldest first, so overdue
 * reminders stop and withheld reports can be released (spec §12).
 */
final class B2bReceivables
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly CurrentScope $currentScope,
    ) {}

    /** @return Money what was applied; anything left over stays as credit on the ledger only */
    public function applySettlementPayment(string $organizationId, string $b2bClientId, Money $amount, string $reference, CarbonImmutable $periodEnd): Money
    {
        return $this->currentScope->runAs(ScopeContext::system($organizationId), function () use ($b2bClientId, $amount, $reference, $periodEnd): Money {
            $invoices = Invoice::query()
                ->where('b2b_client_id', $b2bClientId)
                ->where('bill_to_type', BillToType::B2bClient)
                ->whereIn('payment_status', [InvoicePaymentStatus::Credit, InvoicePaymentStatus::PartiallyPaid])
                ->where('invoice_date', '<=', $periodEnd->toDateString())
                ->whereHas('order', fn ($orders) => $orders->where('status', '!=', OrderStatus::Cancelled))
                ->orderBy('invoice_date')
                ->orderBy('id')
                ->get();

            $remaining = $amount;
            foreach ($invoices as $invoice) {
                if (! $remaining->isGreaterThan(Money::zero())) {
                    break;
                }

                $share = $invoice->balanceDue()->isLessThan($remaining) ? $invoice->balanceDue() : $remaining;

                if ($share->isGreaterThan(Money::zero())) {
                    $this->billing->recordClientSettlementPayment($invoice, $share, $reference);
                    $remaining = $remaining->subtract($share);
                }
            }

            return $amount->subtract($remaining);
        });
    }
}
