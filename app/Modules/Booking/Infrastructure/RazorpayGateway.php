<?php

namespace App\Modules\Booking\Infrastructure;

use App\Modules\Booking\Contracts\GatewayEvent;
use App\Modules\Booking\Contracts\GatewayRefund;
use App\Modules\Booking\Contracts\PaymentGateway;
use App\Modules\Booking\Contracts\PaymentLink;
use App\Modules\Booking\Contracts\PaymentLinkRequest;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Razorpay adapter: payment links, refunds and webhook signatures. */
final class RazorpayGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function __construct(
        private readonly string $keyId,
        private readonly string $keySecret,
        private readonly string $webhookSecret,
    ) {}

    public function name(): string
    {
        return 'razorpay';
    }

    public function createPaymentLink(PaymentLinkRequest $request): PaymentLink
    {
        $response = $this->http()->post('/payment_links', [
            'amount' => $request->amount->paise(),
            'currency' => 'INR',
            'reference_id' => $request->invoiceNo,
            'description' => "Invoice {$request->invoiceNo}",
            'expire_by' => $request->expiresAt->getTimestamp(),
            'customer' => array_filter([
                'name' => $request->customerName,
                'contact' => $request->customerPhone,
                'email' => $request->customerEmail,
            ]),
            // We send the link ourselves through our own templates.
            'notify' => ['sms' => false, 'email' => false],
            'notes' => ['invoice_id' => $request->invoiceId],
        ])->throw();

        return new PaymentLink(
            (string) $response->json('id'),
            (string) $response->json('short_url'),
            CarbonImmutable::createFromTimestampUTC((int) $response->json('expire_by')),
        );
    }

    public function refund(string $gatewayPaymentId, Money $amount, string $reason): GatewayRefund
    {
        $response = $this->http()->post("/payments/{$gatewayPaymentId}/refund", [
            'amount' => $amount->paise(),
            'notes' => ['reason' => $reason],
        ])->throw();

        return new GatewayRefund((string) $response->json('id'), $response->json('status') === 'processed');
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        return $this->webhookSecret !== '' && hash_equals(hash_hmac('sha256', $rawBody, $this->webhookSecret), $signature);
    }

    public function parseWebhook(array $payload): GatewayEvent
    {
        return RazorpayWebhookParser::parse($payload);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withBasicAuth($this->keyId, $this->keySecret)
            ->acceptJson()
            ->timeout(10)
            ->retry(2, 500, throw: false);
    }
}
