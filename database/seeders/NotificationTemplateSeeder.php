<?php

namespace Database\Seeders;

use App\Modules\Network\Models\Organization;
use App\Modules\Shared\Notifications\NotificationChannel;
use App\Modules\Shared\Notifications\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Default English templates (spec §9 rule 1). SMS needs DLT template IDs and
 * WhatsApp needs Meta-approved template names before going live (spec §3);
 * HQ fills `provider_template_id` once they are registered.
 */
class NotificationTemplateSeeder extends Seeder
{
    private const TEMPLATES = [
        'booking_confirmed' => [
            'sms' => [null, 'Dear {{patient_name}}, your booking {{order_no}} for {{test_count}} test(s) is confirmed. Amount Rs {{total}}.'],
            'whatsapp' => [null, 'Hello {{patient_name}}, your booking {{order_no}} for {{test_count}} test(s) is confirmed. Amount: Rs {{total}}.'],
            'email' => ['Booking {{order_no}} confirmed', "Dear {{patient_name}},\n\nYour booking {{order_no}} for {{test_count}} test(s) is confirmed. Amount: Rs {{total}}.\n"],
        ],
        'payment_link' => [
            'sms' => [null, 'Dear {{patient_name}}, pay Rs {{amount}} for invoice {{invoice_no}}: {{payment_url}}'],
            'whatsapp' => [null, 'Hello {{patient_name}}, please pay Rs {{amount}} for invoice {{invoice_no}} here: {{payment_url}}'],
            'email' => ['Payment for invoice {{invoice_no}}', "Dear {{patient_name}},\n\nPlease pay Rs {{amount}} for invoice {{invoice_no}}: {{payment_url}}\n"],
        ],
    ];

    public function run(): void
    {
        foreach (Organization::query()->get() as $organization) {
            foreach (self::TEMPLATES as $eventKey => $channels) {
                foreach ($channels as $channel => [$subject, $body]) {
                    $template = NotificationTemplate::query()->firstOrNew([
                        'organization_id' => $organization->id,
                        'event_key' => $eventKey,
                        'channel' => NotificationChannel::from($channel),
                        'language' => 'en',
                    ]);

                    if ($template->exists) {
                        continue;
                    }

                    $template->fill(['subject' => $subject, 'body_template' => $body]);
                    $template->organization_id = $organization->id;
                    $template->save();
                }
            }
        }
    }
}
