<?php

namespace App\Modules\Shared\Notifications;

/** A vendor's delivery receipt for one message, mapped into our terms. */
final class DeliveryReport
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly NotificationStatus $status,
        public readonly ?string $error,
    ) {}
}
