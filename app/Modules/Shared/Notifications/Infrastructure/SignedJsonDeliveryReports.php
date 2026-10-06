<?php

namespace App\Modules\Shared\Notifications\Infrastructure;

use App\Modules\Shared\Notifications\Contracts\DeliveryReportParser;
use App\Modules\Shared\Notifications\DeliveryReport;
use App\Modules\Shared\Notifications\NotificationStatus;

/**
 * Delivery receipts in our own format, until the SMS and WhatsApp vendors
 * are chosen: a JSON body `{"reports": [{"message_id", "status", "error"}]}`
 * signed with HMAC-SHA256 of the raw body in `X-Signature: sha256=<hex>`.
 * A relay or the vendor adapter posts this shape.
 */
final class SignedJsonDeliveryReports implements DeliveryReportParser
{
    private const STATUSES = [
        'sent' => NotificationStatus::Sent,
        'delivered' => NotificationStatus::Delivered,
        'read' => NotificationStatus::Read,
        'failed' => NotificationStatus::Failed,
        'undelivered' => NotificationStatus::Failed,
    ];

    public function __construct(private readonly string $secret) {}

    public function hasValidSignature(string $rawBody, string $signatureHeader): bool
    {
        if ($this->secret === '') {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $this->secret), trim($signatureHeader));
    }

    public function parse(string $rawBody): array
    {
        $payload = json_decode($rawBody, true);
        $reports = is_array($payload) && is_array($payload['reports'] ?? null) ? $payload['reports'] : [];
        $parsed = [];

        foreach ($reports as $report) {
            $status = self::STATUSES[strtolower((string) ($report['status'] ?? ''))] ?? null;
            $messageId = (string) ($report['message_id'] ?? '');

            if ($status === null || $messageId === '') {
                continue;
            }

            $error = isset($report['error']) ? mb_substr((string) $report['error'], 0, 500) : null;
            $parsed[] = new DeliveryReport($messageId, $status, $error);
        }

        return $parsed;
    }
}
