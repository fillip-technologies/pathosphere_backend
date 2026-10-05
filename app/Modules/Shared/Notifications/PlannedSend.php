<?php

namespace App\Modules\Shared\Notifications;

/** One step of a channel plan: which template to send where. */
final class PlannedSend
{
    public function __construct(
        public readonly NotificationChannel $channel,
        public readonly string $templateId,
        public readonly string $destination,
    ) {}
}
