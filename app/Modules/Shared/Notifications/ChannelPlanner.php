<?php

namespace App\Modules\Shared\Notifications;

/**
 * Orders the channels to try (spec §9 rule 2): WhatsApp → SMS → email,
 * keeping only channels with an active template and a destination, and
 * WhatsApp only for people who opted in.
 */
final class ChannelPlanner
{
    private const PREFERENCE = [NotificationChannel::Whatsapp, NotificationChannel::Sms, NotificationChannel::Email];

    /**
     * @param  array<string, string>  $templateIdByChannel  channel value => template ID
     * @return list<PlannedSend>
     */
    public static function plan(NotificationRecipient $recipient, array $templateIdByChannel): array
    {
        $plan = [];

        foreach (self::PREFERENCE as $channel) {
            $templateId = $templateIdByChannel[$channel->value] ?? null;
            $destination = $channel === NotificationChannel::Email ? $recipient->email : $recipient->phone;

            if ($templateId === null || $destination === null) {
                continue;
            }

            if ($channel === NotificationChannel::Whatsapp && ! $recipient->whatsappOptedIn) {
                continue;
            }

            $plan[] = new PlannedSend($channel, $templateId, $destination);
        }

        return $plan;
    }
}
