<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Modules\Booking\Contracts\PaymentGateway;
use App\Modules\Booking\Errors\BookingError;
use App\Modules\Booking\Jobs\ProcessPaymentWebhook;
use App\Modules\Booking\Models\PaymentWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /webhooks/razorpay. Every callback is stored first (spec §7.5), then
 * processed in a job, so the gateway gets a fast answer and nothing is lost
 * if processing fails. Retries of the same event are answered, not reapplied.
 */
final class PaymentWebhookController
{
    public function __invoke(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);
        $eventId = (string) $request->header('X-Razorpay-Event-Id', '');

        if (! is_array($payload) || $eventId === '') {
            throw ValidationException::withMessages(['payload' => 'Expected a JSON body and an X-Razorpay-Event-Id header.']);
        }

        $signatureValid = $gateway->verifyWebhookSignature($rawBody, (string) $request->header('X-Razorpay-Signature', ''));

        $event = PaymentWebhookEvent::query()->firstOrCreate(
            ['gateway' => $gateway->name(), 'event_id' => $eventId],
            ['event_type' => mb_substr((string) ($payload['event'] ?? 'unknown'), 0, 60), 'payload' => $payload, 'signature_valid' => $signatureValid],
        );

        if (! $signatureValid) {
            throw BookingError::invalidWebhookSignature();
        }

        if ($event->wasRecentlyCreated) {
            ProcessPaymentWebhook::dispatch($event->id)->afterCommit();
        }

        return new JsonResponse(['data' => ['received' => true, 'duplicate' => ! $event->wasRecentlyCreated]]);
    }
}
