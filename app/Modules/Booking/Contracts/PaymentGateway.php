<?php

namespace App\Modules\Booking\Contracts;

use App\Modules\Shared\Money\Money;

/**
 * Online payments vendor boundary (Razorpay, Cashfree, PayU — spec §3).
 * Webhooks are the source of truth for payments; never trust a client's word
 * that it paid.
 */
interface PaymentGateway
{
    /** Stored on payments.gateway, e.g. "razorpay". */
    public function name(): string;

    public function createPaymentLink(PaymentLinkRequest $request): PaymentLink;

    public function refund(string $gatewayPaymentId, Money $amount, string $reason): GatewayRefund;

    public function verifyWebhookSignature(string $rawBody, string $signature): bool;

    /** @param  array<string, mixed>  $payload */
    public function parseWebhook(array $payload): GatewayEvent;
}
