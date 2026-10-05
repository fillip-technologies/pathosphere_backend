<?php

namespace App\Modules\Shared\Notifications;

/** Who a message is for and where it can reach them. */
final class NotificationRecipient
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $organizationId,
        public readonly ?string $phone,
        public readonly ?string $email,
        /** WhatsApp is used only after the person opted in (spec §9 rule 3). */
        public readonly bool $whatsappOptedIn,
    ) {}
}
