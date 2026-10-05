<?php

namespace App\Modules\Shared\Notifications;

/** What a vendor told us after accepting a message. */
final class SendReceipt
{
    public function __construct(public readonly ?string $providerMessageId) {}
}
