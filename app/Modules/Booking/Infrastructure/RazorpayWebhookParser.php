<?php

namespace App\Modules\Booking\Infrastructure;

use App\Modules\Booking\Contracts\GatewayEvent;
use App\Modules\Booking\Enums\PaymentMode;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Reads Razorpay webhook payloads. Only `payment_link.paid` (payment against
 * one of our invoice links) and `refund.processed` matter; everything else is
 * ignored but still stored.
 */
final class RazorpayWebhookParser
{
    /** @param  array<string, mixed>  $payload */
    public static function parse(array $payload): GatewayEvent
    {
        return match ($payload['event'] ?? null) {
            'payment_link.paid' => self::paymentLinkPaid($payload),
            'refund.processed' => GatewayEvent::refundProcessed((string) data_get($payload, 'payload.refund.entity.id')),
            default => GatewayEvent::ignored(),
        };
    }

    /** @param  array<string, mixed>  $payload */
    private static function paymentLinkPaid(array $payload): GatewayEvent
    {
        $payment = (array) data_get($payload, 'payload.payment.entity', []);

        return GatewayEvent::paymentCaptured(
            (string) data_get($payload, 'payload.payment_link.entity.notes.invoice_id'),
            (string) $payment['id'],
            Money::fromPaise((int) $payment['amount']),
            self::mode((string) ($payment['method'] ?? '')),
            CarbonImmutable::createFromTimestampUTC((int) ($payment['created_at'] ?? time())),
        );
    }

    private static function mode(string $razorpayMethod): PaymentMode
    {
        return match ($razorpayMethod) {
            'card' => PaymentMode::Card,
            'netbanking' => PaymentMode::Netbanking,
            'wallet' => PaymentMode::Wallet,
            default => PaymentMode::Upi,
        };
    }
}
