<?php

namespace App\Modules\Booking\Events;

use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The gateway confirmed a payment that is not for an invoice (spec §9
 * PaymentCaptured): today, a franchise wallet top-up. Invoice payments are
 * applied by billing directly. Dispatched synchronously inside the webhook
 * job, so a failing listener makes the job retry instead of losing money.
 */
final class PaymentCaptured
{
    public function __construct(
        public readonly string $purpose,
        public readonly string $referenceId,
        public readonly string $gateway,
        public readonly string $gatewayPaymentId,
        public readonly Money $amount,
        public readonly CarbonImmutable $paidAt,
    ) {}
}
