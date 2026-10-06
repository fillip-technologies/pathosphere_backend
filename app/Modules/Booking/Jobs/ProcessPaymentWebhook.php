<?php

namespace App\Modules\Booking\Jobs;

use App\Modules\Booking\Contracts\GatewayEvent;
use App\Modules\Booking\Contracts\PaymentGateway;
use App\Modules\Booking\Contracts\PaymentLinkRequest;
use App\Modules\Booking\Events\PaymentCaptured;
use App\Modules\Booking\Models\PaymentWebhookEvent;
use App\Modules\Booking\Services\BillingService;
use App\Modules\Shared\Jobs\WithSystemScope;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Applies a stored gateway webhook (spec §3: webhooks are the source of
 * truth). Idempotent: processed events are skipped, and payments are unique
 * per gateway transaction.
 */
final class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly string $webhookEventId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        // A gateway event names its invoice; it is not tied to one organization up front.
        return [new WithSystemScope(null)];
    }

    public function handle(PaymentGateway $gateway, BillingService $billing): void
    {
        $webhook = PaymentWebhookEvent::query()->findOrFail($this->webhookEventId);

        if ($webhook->processed_at !== null || ! $webhook->signature_valid) {
            return;
        }

        $event = $gateway->parseWebhook($webhook->payload);

        match ($event->type) {
            GatewayEvent::PAYMENT_CAPTURED => $billing->recordGatewayPayment($event),
            GatewayEvent::WALLET_TOPUP_CAPTURED => event(new PaymentCaptured(
                PaymentLinkRequest::WALLET_TOPUP,
                (string) $event->walletTopupId,
                $gateway->name(),
                (string) $event->paymentId,
                $event->amount ?? Money::zero(),
                $event->occurredAt ?? CarbonImmutable::now(),
            )),
            GatewayEvent::REFUND_PROCESSED => $billing->markRefundProcessed((string) $event->refundId),
            default => null,
        };

        $webhook->update(['processed_at' => now(), 'error' => null]);
    }

    public function failed(Throwable $exception): void
    {
        PaymentWebhookEvent::query()->whereKey($this->webhookEventId)->update(['error' => mb_substr($exception->getMessage(), 0, 1000)]);
    }
}
