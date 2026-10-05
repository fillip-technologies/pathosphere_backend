<?php

namespace App\Modules\Shared\Notifications;

use App\Modules\Shared\Notifications\Jobs\SendNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The one way to message patients, doctors and staff (spec §9). Picks the
 * channels, records a `notifications` row and queues the send after the
 * current transaction commits.
 */
final class NotificationService
{
    private const LANGUAGE = 'en';

    /**
     * @param  array<string, string>  $variables
     * @param  bool  $urgent  critical alerts: skip quiet hours, use the critical queue
     */
    public function notify(string $eventKey, NotificationRecipient $recipient, array $variables, bool $urgent = false): ?Notification
    {
        $templateIdByChannel = NotificationTemplate::query()
            ->where('organization_id', $recipient->organizationId)
            ->where('event_key', $eventKey)
            ->where('language', self::LANGUAGE)
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (NotificationTemplate $template) => [$template->channel->value => $template->id])
            ->all();

        $plan = ChannelPlanner::plan($recipient, $templateIdByChannel);

        if ($plan === []) {
            Log::warning('Notification skipped: no active template or destination.', [
                'event_key' => $eventKey,
                'recipient_type' => $recipient->type,
                'recipient_id' => $recipient->id,
            ]);

            return null;
        }

        return $this->queue($recipient->type, $recipient->id, $recipient->organizationId, $variables, $plan, $urgent);
    }

    /**
     * Sends the first step of the plan; later steps are the fallbacks used if
     * it keeps failing (spec §9 rule 5).
     *
     * @param  array<string, string>  $variables
     * @param  non-empty-list<PlannedSend>  $plan
     */
    public function queue(string $recipientType, string $recipientId, string $organizationId, array $variables, array $plan, bool $urgent): Notification
    {
        $step = array_shift($plan);

        $notification = Notification::query()->create([
            'organization_id' => $organizationId,
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'template_id' => $step->templateId,
            'channel' => $step->channel,
            'destination' => $step->destination,
            'payload' => $variables,
            'status' => NotificationStatus::Queued,
        ]);

        SendNotification::dispatch($notification->id, $organizationId, $plan, $urgent)
            ->onQueue($urgent ? 'critical' : 'default')
            ->delay(QuietHours::deliverAt(CarbonImmutable::now(), $urgent))
            ->afterCommit();

        return $notification;
    }
}
