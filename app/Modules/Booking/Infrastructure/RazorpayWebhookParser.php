<?php

namespace App\Modules\Booking\Infrastructure;

use App\Modules\Booking\Contracts\GatewayEvent;
use App\Modules\Booking\Contracts\PaymentLinkRequest;
use App\Modules\Booking\Enums\PaymentMode;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Reads Razorpay webhook payloads. Only `payment_link.paid` (payment against
 * one of our invoice or wallet top-up links) and `refund.processed` matter;
 * everything else is ignored but still stored.
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

    /**
     * The notes we attach to a link, so the webhook says what was paid for.
     *
     * @return array<string, string>
     */
    public static function notesFor(PaymentLinkRequest $request): array
    {
        return match ($request->purpose) {
            PaymentLinkRequest::WALLET_TOPUP => ['wallet_topup_id' => $request->referenceId],
            default => ['invoice_id' => $request->referenceId],
        };
    }

    /** @param  array<string, mixed>  $payload */
    private static function paymentLinkPaid(array $payload): GatewayEvent
    {
        $payment = (array) data_get($payload, 'payload.payment.entity', []);
        $notes = (array) data_get($payload, 'payload.payment_link.entity.notes', []);
        $paymentId = (string) $payment['id'];
        $amount = Money::fromPaise((int) $payment['amount']);
        $mode = self::mode((string) ($payment['method'] ?? ''));
        $paidAt = CarbonImmutable::createFromTimestampUTC((int) ($payment['created_at'] ?? time()));

        if (isset($notes['wallet_topup_id'])) {
            return GatewayEvent::walletTopupCaptured((string) $notes['wallet_topup_id'], $paymentId, $amount, $mode, $paidAt);
        }

        return GatewayEvent::paymentCaptured((string) ($notes['invoice_id'] ?? ''), $paymentId, $amount, $mode, $paidAt);
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
