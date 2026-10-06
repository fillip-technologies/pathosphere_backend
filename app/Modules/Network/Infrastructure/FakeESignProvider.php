<?php

namespace App\Modules\Network\Infrastructure;

use App\Modules\Network\Contracts\ESignEvent;
use App\Modules\Network\Contracts\ESignProvider;
use App\Modules\Network\Contracts\ESignRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Local and test stand-in for the e-sign vendor, until one is chosen. Its
 * webhook is our own signed JSON format, the same scheme as delivery
 * receipts: `{"reference", "status": "signed"|"declined", "occurred_at"}`
 * signed with HMAC-SHA256 of the raw body in `X-Signature: sha256=<hex>`.
 */
final class FakeESignProvider implements ESignProvider
{
    public function __construct(private readonly string $webhookSecret) {}

    public function requestSignature(ESignRequest $request): string
    {
        return 'esign_fake_'.Str::lower(Str::random(16));
    }

    public function hasValidSignature(string $rawBody, string $signatureHeader): bool
    {
        if ($this->webhookSecret === '') {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $this->webhookSecret), trim($signatureHeader));
    }

    public function parseWebhook(string $rawBody): ?ESignEvent
    {
        $payload = json_decode($rawBody, true);
        $reference = is_array($payload) ? (string) ($payload['reference'] ?? '') : '';
        $outcome = match (is_array($payload) ? ($payload['status'] ?? null) : null) {
            'signed' => ESignEvent::SIGNED,
            'declined', 'expired' => ESignEvent::DECLINED,
            default => null,
        };

        if ($reference === '' || $outcome === null) {
            return null;
        }

        $occurredAt = isset($payload['occurred_at']) ? CarbonImmutable::parse((string) $payload['occurred_at']) : CarbonImmutable::now();

        return new ESignEvent($reference, $outcome, $occurredAt->utc());
    }

    public function downloadSignedDocument(string $reference): string
    {
        return "%PDF-1.4\n% Signed agreement {$reference} (fake e-sign)\n%%EOF\n";
    }
}
