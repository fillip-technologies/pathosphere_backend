<?php

namespace App\Modules\Shared\Notifications\Contracts;

use App\Modules\Shared\Notifications\DeliveryReport;

/**
 * Reads delivery webhooks (spec §9 rule 5: /webhooks/sms-dlr and
 * /webhooks/whatsapp). Each vendor signs and shapes them differently; the
 * adapter checks the signature and maps the payload.
 */
interface DeliveryReportParser
{
    /** Whether the raw body really came from the vendor. */
    public function hasValidSignature(string $rawBody, string $signatureHeader): bool;

    /** @return list<DeliveryReport> */
    public function parse(string $rawBody): array;
}
