<?php

namespace App\Modules\Booking\Contracts;

use App\Modules\Booking\Enums\PaymentMode;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/** A gateway webhook, translated out of the vendor's format. */
final class GatewayEvent
{
    public const PAYMENT_CAPTURED = 'payment_captured';

    public const REFUND_PROCESSED = 'refund_processed';

    public const IGNORED = 'ignored';

    private function __construct(
        public readonly string $type,
        public readonly ?string $invoiceId = null,
        public readonly ?string $paymentId = null,
        public readonly ?Money $amount = null,
        public readonly ?PaymentMode $mode = null,
        public readonly ?CarbonImmutable $occurredAt = null,
        public readonly ?string $refundId = null,
    ) {}

    public static function paymentCaptured(string $invoiceId, string $paymentId, Money $amount, PaymentMode $mode, CarbonImmutable $paidAt): self
    {
        return new self(self::PAYMENT_CAPTURED, $invoiceId, $paymentId, $amount, $mode, $paidAt);
    }

    public static function refundProcessed(string $refundId): self
    {
        return new self(self::REFUND_PROCESSED, refundId: $refundId);
    }

    public static function ignored(): self
    {
        return new self(self::IGNORED);
    }
}
