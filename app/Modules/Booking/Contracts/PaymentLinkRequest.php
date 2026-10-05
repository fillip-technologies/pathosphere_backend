<?php

namespace App\Modules\Booking\Contracts;

use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

final class PaymentLinkRequest
{
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $invoiceNo,
        public readonly Money $amount,
        public readonly string $customerName,
        public readonly string $customerPhone,
        public readonly ?string $customerEmail,
        public readonly CarbonImmutable $expiresAt,
    ) {}
}
