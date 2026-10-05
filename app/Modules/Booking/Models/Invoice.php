<?php

namespace App\Modules\Booking\Models;

use App\Modules\Booking\Enums\BillToType;
use App\Modules\Booking\Enums\InvoicePaymentStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bill for an order (spec §7.5): amount (gross) − discount + tax = total.
 * Processing labs never see other branches' invoices (spec §4), so invoices
 * are scoped by the billing branch only.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $invoice_no
 * @property string $order_id
 * @property string $billed_by_branch_id
 * @property string|null $franchise_id
 * @property BillToType $bill_to_type
 * @property string|null $b2b_client_id
 * @property CarbonImmutable $invoice_date
 * @property CarbonImmutable|null $due_date
 * @property Money $amount
 * @property Money $discount
 * @property Money $tax
 * @property Money $total
 * @property Money $amount_paid
 * @property InvoicePaymentStatus $payment_status
 * @property Order $order
 * @property Collection<int, Payment> $payments
 */
final class Invoice extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    protected function casts(): array
    {
        return [
            'bill_to_type' => BillToType::class,
            'invoice_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'amount' => MoneyCast::class,
            'discount' => MoneyCast::class,
            'tax' => MoneyCast::class,
            'total' => MoneyCast::class,
            'amount_paid' => MoneyCast::class,
            'payment_status' => InvoicePaymentStatus::class,
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(branch: 'billed_by_branch_id', franchise: 'franchise_id', b2bClient: 'b2b_client_id');
    }

    public function balanceDue(): Money
    {
        return $this->total->subtract($this->amount_paid);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
