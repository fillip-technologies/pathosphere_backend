<?php

namespace App\Modules\Booking\Models;

use App\Modules\Booking\Enums\PaymentMode;
use App\Modules\Booking\Enums\PaymentStatus;
use App\Modules\Booking\Enums\RefundStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money received against an invoice. Reached through its invoice, which
 * carries the scope. (gateway, transaction_id) is unique so a gateway payment
 * is recorded once however many webhooks arrive.
 *
 * @property string $id
 * @property string $invoice_id
 * @property Money $amount
 * @property PaymentMode $mode
 * @property string|null $gateway
 * @property string|null $transaction_id
 * @property string|null $received_by
 * @property CarbonImmutable $paid_at
 * @property PaymentStatus $status
 * @property Invoice $invoice
 * @property Collection<int, Refund> $refunds
 */
final class Payment extends BaseModel
{
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'mode' => PaymentMode::class,
            'paid_at' => 'immutable_datetime',
            'status' => PaymentStatus::class,
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** What can still be refunded: the amount minus refunds not failed. */
    public function refundableAmount(): Money
    {
        $refunded = $this->refunds
            ->reject(fn (Refund $refund) => $refund->status === RefundStatus::Failed)
            ->reduce(fn (Money $total, Refund $refund) => $total->add($refund->amount), Money::zero());

        return $this->amount->subtract($refunded);
    }
}
