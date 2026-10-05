<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\PaymentMode;
use App\Modules\Shared\Money\Money;

/** A payment taken in person: cash, card machine or UPI QR. */
final class DeskPayment
{
    public function __construct(
        public readonly PaymentMode $mode,
        public readonly Money $amount,
        public readonly ?string $transactionId,
    ) {}
}
