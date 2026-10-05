<?php

namespace App\Modules\Shared\Notifications\Jobs;

use App\Modules\Shared\Jobs\WithSystemScope;
use App\Modules\Shared\Notifications\Contracts\EmailSender;
use App\Modules\Shared\Notifications\Contracts\SmsSender;
use App\Modules\Shared\Notifications\Contracts\WhatsAppSender;
use App\Modules\Shared\Notifications\Notification;
use App\Modules\Shared\Notifications\NotificationChannel;
use App\Modules\Shared\Notifications\NotificationService;
use App\Modules\Shared\Notifications\NotificationStatus;
use App\Modules\Shared\Notifications\PlannedSend;
use App\Modules\Shared\Notifications\SendReceipt;
use App\Modules\Shared\Notifications\TemplateRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LogicException;
use Throwable;

/**
 * Sends one queued notification. Idempotent: a notification that was already
 * sent is never sent again. After three failed tries the next channel of the
 * plan is used (spec §9 rule 5).
 */
final class SendNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** @param  list<PlannedSend>  $fallbacks */
    public function __construct(
        public readonly string $notificationId,
        public readonly string $organizationId,
        public readonly array $fallbacks,
        public readonly bool $urgent,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new WithSystemScope($this->organizationId)];
    }

    public function handle(SmsSender $sms, WhatsAppSender $whatsApp, EmailSender $email): void
    {
        $notification = Notification::query()->with('template')->findOrFail($this->notificationId);

        if ($notification->status !== NotificationStatus::Queued) {
            return;
        }

        $template = $notification->template;
        $body = TemplateRenderer::render($template->body_template, $notification->payload);

        $receipt = match ($notification->channel) {
            NotificationChannel::Sms => $sms->sendSms($notification->destination, $body, $template->provider_template_id),
            NotificationChannel::Whatsapp => $whatsApp->sendWhatsApp(
                $notification->destination,
                $template->provider_template_id ?? $template->event_key,
                $notification->payload,
                $body,
            ),
            NotificationChannel::Email => $email->sendEmail(
                $notification->destination,
                TemplateRenderer::render((string) $template->subject, $notification->payload),
                $body,
            ),
            NotificationChannel::Push => throw new LogicException('Push notifications are not built yet.'),
        };

        $this->markSent($notification, $receipt);
    }

    public function failed(Throwable $exception): void
    {
        WithSystemScope::run($this->organizationId, function () use ($exception): void {
            $notification = Notification::query()->find($this->notificationId);

            if ($notification === null) {
                return;
            }

            // The vendor's message, never the payload: it may hold patient data.
            $notification->update(['status' => NotificationStatus::Failed, 'error' => mb_substr($exception->getMessage(), 0, 500)]);

            if ($this->fallbacks !== []) {
                app(NotificationService::class)->queue(
                    $notification->recipient_type,
                    $notification->recipient_id,
                    $notification->organization_id,
                    $notification->payload,
                    $this->fallbacks,
                    $this->urgent,
                );
            }
        });
    }

    private function markSent(Notification $notification, SendReceipt $receipt): void
    {
        $notification->update([
            'status' => NotificationStatus::Sent,
            'provider_message_id' => $receipt->providerMessageId,
            'sent_at' => now(),
        ]);
    }
}
