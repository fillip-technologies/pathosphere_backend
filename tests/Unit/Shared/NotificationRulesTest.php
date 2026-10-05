<?php

namespace Tests\Unit\Shared;

use App\Modules\Shared\Notifications\ChannelPlanner;
use App\Modules\Shared\Notifications\NotificationChannel;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\QuietHours;
use App\Modules\Shared\Notifications\TemplateRenderer;
use Carbon\CarbonImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

final class NotificationRulesTest extends TestCase
{
    private const ALL_TEMPLATES = ['whatsapp' => 't-wa', 'sms' => 't-sms', 'email' => 't-email'];

    public function test_whatsapp_first_only_for_opted_in_people(): void
    {
        $optedIn = new NotificationRecipient('patient', 'p1', 'org', '9876501234', 'a@example.com', true);
        $notOptedIn = new NotificationRecipient('patient', 'p1', 'org', '9876501234', 'a@example.com', false);

        $this->assertSame(
            [NotificationChannel::Whatsapp, NotificationChannel::Sms, NotificationChannel::Email],
            array_map(fn ($step) => $step->channel, ChannelPlanner::plan($optedIn, self::ALL_TEMPLATES)),
        );
        $this->assertSame(
            [NotificationChannel::Sms, NotificationChannel::Email],
            array_map(fn ($step) => $step->channel, ChannelPlanner::plan($notOptedIn, self::ALL_TEMPLATES)),
        );
    }

    public function test_channels_without_a_template_or_destination_are_skipped(): void
    {
        $noEmail = new NotificationRecipient('patient', 'p1', 'org', '9876501234', null, false);

        $this->assertSame([], ChannelPlanner::plan($noEmail, ['email' => 't-email']));
    }

    public function test_quiet_hours_hold_messages_until_eight_in_india(): void
    {
        // 22:30 IST = 17:00 UTC.
        $lateEvening = CarbonImmutable::parse('2026-10-05 17:00', 'UTC');
        $this->assertSame('2026-10-06 02:30', QuietHours::deliverAt($lateEvening, urgent: false)?->format('Y-m-d H:i'));

        // 06:00 IST the same morning = 00:30 UTC.
        $earlyMorning = CarbonImmutable::parse('2026-10-06 00:30', 'UTC');
        $this->assertSame('2026-10-06 02:30', QuietHours::deliverAt($earlyMorning, urgent: false)?->format('Y-m-d H:i'));

        $this->assertNull(QuietHours::deliverAt(CarbonImmutable::parse('2026-10-06 06:00', 'UTC'), urgent: false));
        $this->assertNull(QuietHours::deliverAt($lateEvening, urgent: true));
    }

    public function test_templates_render_and_missing_variables_fail(): void
    {
        $this->assertSame('Hi Asha, order X1', TemplateRenderer::render('Hi {{patient_name}}, order {{ order_no }}', ['patient_name' => 'Asha', 'order_no' => 'X1']));

        $this->expectException(LogicException::class);
        TemplateRenderer::render('Hi {{patient_name}}', []);
    }
}
