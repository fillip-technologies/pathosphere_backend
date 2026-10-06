<?php

namespace App\Modules\Booking\Contracts;

use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * What a payment link collects for: an invoice balance, or a franchise
 * wallet top-up. The purpose and reference travel with the link and come
 * back on the gateway's webhook.
 */
final class PaymentLinkRequest
{
    public const INVOICE = 'invoice';

    public const WALLET_TOPUP = 'wallet_topup';

    public function __construct(
        public readonly string $purpose,
        public readonly string $referenceId,
        /** Human-readable: the invoice number, or the franchise code for a top-up. */
        public readonly string $referenceNo,
        public readonly string $description,
        public readonly Money $amount,
        public readonly string $customerName,
        public readonly string $customerPhone,
        public readonly ?string $customerEmail,
        public readonly CarbonImmutable $expiresAt,
    ) {}
}
