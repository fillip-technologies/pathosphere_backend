<?php

namespace App\Modules\Booking\Infrastructure;

use App\Modules\Booking\Contracts\GatewayEvent;
use App\Modules\Booking\Contracts\GatewayRefund;
use App\Modules\Booking\Contracts\PaymentGateway;
use App\Modules\Booking\Contracts\PaymentLink;
use App\Modules\Booking\Contracts\PaymentLinkRequest;
use App\Modules\Shared\Money\Money;
use Illuminate\Support\Str;

/**
 * Local and test stand-in that behaves like Razorpay: same webhook format and
 * signature scheme (HMAC-SHA256 with the configured webhook secret), refunds
 * processed at once, no network calls.
 */
final class FakePaymentGateway implements PaymentGateway
{
    public function __construct(private readonly string $webhookSecret) {}

    public function name(): string
    {
        return 'razorpay';
    }

    public function createPaymentLink(PaymentLinkRequest $request): PaymentLink
    {
        $linkId = 'plink_fake_'.Str::random(14);

        return new PaymentLink($linkId, "https://pay.example.test/{$linkId}", $request->expiresAt);
    }

    public function refund(string $gatewayPaymentId, Money $amount, string $reason): GatewayRefund
    {
        return new GatewayRefund('rfnd_fake_'.Str::random(14), true);
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        return hash_equals(hash_hmac('sha256', $rawBody, $this->webhookSecret), $signature);
    }

    public function parseWebhook(array $payload): GatewayEvent
    {
        return RazorpayWebhookParser::parse($payload);
    }
}
