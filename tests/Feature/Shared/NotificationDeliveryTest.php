<?php

namespace Tests\Feature\Shared;

use App\Modules\Shared\Notifications\Contracts\EmailSender;
use App\Modules\Shared\Notifications\Contracts\SmsSender;
use App\Modules\Shared\Notifications\Contracts\WhatsAppSender;
use App\Modules\Shared\Notifications\Notification;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\NotificationService;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Fakes\RecordingMessageSender;
use Tests\TestCase;

/** Template sends, channel fallback and the notifications log (spec §9). */
final class NotificationDeliveryTest extends TestCase
{
    use BuildsStaff;
    use RefreshDatabase;

    private RecordingMessageSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->asSystem(fn () => $this->seed(NotificationTemplateSeeder::class));

        $this->sender = new RecordingMessageSender;
        foreach ([SmsSender::class, WhatsAppSender::class, EmailSender::class] as $contract) {
            $this->app->instance($contract, $this->sender);
        }
    }

    public function test_a_failing_channel_falls_back_to_the_next_one(): void
    {
        $this->sender->failingChannels = ['whatsapp'];

        try {
            $this->asSystem(fn () => app(NotificationService::class)->notify('booking_confirmed', $this->recipient(), $this->variables(), urgent: true));
        } catch (RuntimeException) {
            // The sync queue rethrows the WhatsApp failure after running the fallback.
        }

        $this->assertSame(['sms'], $this->sender->channelsUsed());
        $this->assertStringContainsString('ORD-1', $this->sender->sent[0]['body']);

        $log = $this->asSystem(fn () => Notification::query()->orderBy('id')->get());
        $this->assertSame([['whatsapp', 'failed'], ['sms', 'sent']], $log->map(fn (Notification $n) => [$n->channel->value, $n->status->value])->all());
        $this->assertSame('******1234', $log[1]->maskedDestination());
    }

    public function test_an_event_without_templates_is_skipped_not_failed(): void
    {
        $result = $this->asSystem(fn () => app(NotificationService::class)->notify('no_such_event', $this->recipient(), []));

        $this->assertNull($result);
        $this->assertSame([], $this->sender->sent);
    }

    private function recipient(): NotificationRecipient
    {
        return new NotificationRecipient('patient', (string) Str::uuid(), $this->organization->id, '9876501234', null, whatsappOptedIn: true);
    }

    /** @return array<string, string> */
    private function variables(): array
    {
        return ['patient_name' => 'Asha', 'order_no' => 'ORD-1', 'test_count' => '2', 'total' => '949.00'];
    }
}
