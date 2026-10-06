<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\OrderStatus;
use App\Modules\Booking\Enums\PaymentMode;
use App\Modules\Booking\Enums\PaymentStatus;
use App\Modules\Booking\Enums\RefundStatus;
use App\Modules\Booking\Models\Invoice;
use App\Modules\Booking\Models\Payment;
use App\Modules\Booking\Models\Refund;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Billing totals for the accounting export (spec §3: Tally / Zoho, export
 * only). HQ's books hold the sales of company branches; a franchise's sales
 * are its own, and reach HQ's books through the partner ledger. The one
 * exception is online money for franchise patients, which lands in HQ's
 * gateway account and is reported so it can be accounted for.
 *
 * Totals are per business day in India, summed in the database (decimal,
 * never float). Invoices of cancelled orders are left out, as in
 * settlements; their refunds still appear, so the money nets to zero.
 */
final class AccountingFacts
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public function __construct(private readonly CurrentScope $currentScope) {}

    /** @return list<DailyBilling> */
    public function billing(string $organizationId, CarbonImmutable $date): array
    {
        return $this->currentScope->runAs(ScopeContext::system($organizationId), fn (): array => Invoice::query()
            ->whereNull('franchise_id')
            ->where('invoice_date', $date->toDateString())
            ->whereHas('order', fn ($orders) => $orders->where('status', '!=', OrderStatus::Cancelled))
            ->groupBy('billed_by_branch_id', 'b2b_client_id')
            ->orderBy('billed_by_branch_id')
            ->orderBy('b2b_client_id')
            ->selectRaw('billed_by_branch_id, b2b_client_id, count(*) as invoice_count, sum(amount) as amount, sum(discount) as discount, sum(tax) as tax, sum(total) as total')
            ->toBase()
            ->get()
            ->map(fn (object $row) => new DailyBilling(
                $date,
                (string) $row->billed_by_branch_id,
                $row->b2b_client_id === null ? null : (string) $row->b2b_client_id,
                (int) $row->invoice_count,
                Money::fromString((string) $row->amount),
                Money::fromString((string) $row->discount),
                Money::fromString((string) $row->tax),
                Money::fromString((string) $row->total),
            ))
            ->values()
            ->all());
    }

    /** @return list<DailyCollection> receipts, then refunds */
    public function collections(string $organizationId, CarbonImmutable $date): array
    {
        $fromUtc = CarbonImmutable::parse($date->toDateString(), self::BUSINESS_TIMEZONE)->utc();
        $untilUtc = $fromUtc->addDay();

        return $this->currentScope->runAs(ScopeContext::system($organizationId), function () use ($organizationId, $date, $fromUtc, $untilUtc): array {
            $receipts = $this->withInvoice(Payment::query(), $organizationId)
                ->where('payments.status', PaymentStatus::Success)
                ->where('payments.paid_at', '>=', $fromUtc)
                ->where('payments.paid_at', '<', $untilUtc);

            // Refunds that are not failed count from the day they were made, as in invoices and settlements.
            $refunds = $this->withInvoice(Refund::query()->join('payments', 'payments.id', '=', 'refunds.payment_id'), $organizationId)
                ->where('refunds.status', '!=', RefundStatus::Failed)
                ->where('refunds.created_at', '>=', $fromUtc)
                ->where('refunds.created_at', '<', $untilUtc);

            return [...$this->totals($receipts, $date, false), ...$this->totals($refunds, $date, true)];
        });
    }

    /**
     * Company-branch invoices, and franchise invoices paid online. Payments
     * carry no scope of their own, so the organization is checked on the invoice.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function withInvoice(Builder $query, string $organizationId): Builder
    {
        return $query
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.organization_id', $organizationId)
            ->where(fn ($invoices) => $invoices->whereNull('invoices.franchise_id')->orWhereNotNull('payments.gateway'));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return list<DailyCollection>
     */
    private function totals(Builder $query, CarbonImmutable $date, bool $isRefund): array
    {
        $amountColumn = $isRefund ? 'refunds.amount' : 'payments.amount';

        return $query
            ->groupBy('invoices.billed_by_branch_id', 'invoices.b2b_client_id', 'payments.mode')
            ->selectRaw("invoices.billed_by_branch_id as branch_id, invoices.b2b_client_id, max(invoices.franchise_id) as franchise_id, payments.mode, count(*) as entry_count, sum({$amountColumn}) as amount")
            ->orderBy('invoices.billed_by_branch_id')
            ->orderBy('invoices.b2b_client_id')
            ->orderBy('payments.mode')
            ->toBase()
            ->get()
            ->map(fn (object $row) => new DailyCollection(
                $date,
                (string) $row->branch_id,
                $row->b2b_client_id === null ? null : (string) $row->b2b_client_id,
                $row->franchise_id !== null,
                PaymentMode::from((string) $row->mode),
                $isRefund,
                (int) $row->entry_count,
                Money::fromString((string) $row->amount),
            ))
            ->values()
            ->all();
    }
}
