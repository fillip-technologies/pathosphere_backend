<?php

namespace App\Modules\Booking\Models;

use App\Modules\Booking\Enums\RefundStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;

/**
 * Money returned against a payment (spec §7.5 addition).
 *
 * @property string $id
 * @property string $payment_id
 * @property Money $amount
 * @property string $reason
 * @property string|null $gateway_refund_id
 * @property string $approved_by
 * @property RefundStatus $status
 */
final class Refund extends BaseModel
{
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'status' => RefundStatus::class,
        ];
    }
}
