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
        // Sample journey (spec §5.4, §9). Branch alerts go to the branch phone by SMS.
        'sample_rejected' => [
            'sms' => [null, 'Sample {{barcode}} of order {{order_no}} was rejected at {{lab_name}} ({{reason}}). Redraw with barcode {{redraw_barcode}}.'],
        ],
        'sample_recollection' => [
            'sms' => [null, 'Dear {{patient_name}}, your sample for order {{order_no}} could not be tested ({{reason}}). Please visit {{branch_name}} for a free re-collection.'],
            'whatsapp' => [null, 'Hello {{patient_name}}, we could not test your sample for order {{order_no}} ({{reason}}). Please visit {{branch_name}} for a free re-collection.'],
            'email' => ['Re-collection needed for order {{order_no}}', "Dear {{patient_name}},\n\nWe could not test your sample for order {{order_no}} ({{reason}}). Please visit {{branch_name}} for a free re-collection.\n"],
        ],
        'manifest_dispatched' => [
            'sms' => [null, 'Manifest {{manifest_no}} with {{sample_count}} sample(s) left {{from_branch}}. Courier: {{courier_name}}.'],
        ],
        'samples_missing' => [
            'sms' => [null, 'Manifest {{manifest_no}} ({{from_branch}} to {{to_branch}}): {{missing_count}} sample(s) not scanned in at the lab.'],
        ],
        // Lab and reports (spec §5.5, §9). Report links are short-lived signed URLs.
        'report_ready' => [
            'sms' => [null, 'Dear {{patient_name}}, your report for order {{order_no}} is ready: {{report_url}}'],
            'whatsapp' => [null, 'Hello {{patient_name}}, your lab report for order {{order_no}} from {{lab_name}} is ready. Download it here: {{report_url}}'],
            'email' => ['Your report for order {{order_no}} is ready', "Dear {{patient_name}},\n\nYour lab report for order {{order_no}} is ready. Download it here (link valid for a limited time): {{report_url}}\n"],
        ],
        'report_amended' => [
            'sms' => [null, 'Dear {{patient_name}}, your report for order {{order_no}} has been corrected. New report: {{report_url}}'],
            'whatsapp' => [null, 'Hello {{patient_name}}, your lab report for order {{order_no}} from {{lab_name}} has been corrected. Please use the new version: {{report_url}}'],
            'email' => ['Corrected report for order {{order_no}}', "Dear {{patient_name}},\n\nYour lab report for order {{order_no}} has been corrected. Please use the new version: {{report_url}}\n"],
        ],
        'doctor_report_ready' => [
            'sms' => [null, 'Report{{amended_note}} for {{patient_name}}, order {{order_no}}, from {{lab_name}}: {{report_url}}'],
            'whatsapp' => [null, 'Report{{amended_note}} for your patient {{patient_name}} (order {{order_no}}) from {{lab_name}}: {{report_url}}'],
            'email' => ['Report{{amended_note}} for {{patient_name}}', "Report{{amended_note}} for your patient {{patient_name}} (order {{order_no}}) from {{lab_name}}: {{report_url}}\n"],
        ],
        'critical_value' => [
            'sms' => [null, 'CRITICAL: {{parameter}} {{value}} for {{patient_name}} (UHID {{uhid}}, order {{order_no}}) at {{lab_name}}. Please act now.'],
            'whatsapp' => [null, 'CRITICAL RESULT: {{parameter}} {{value}} for {{patient_name}} (UHID {{uhid}}, order {{order_no}}) at {{lab_name}}. Please act now.'],
        ],
        'tat_breach' => [
            'sms' => [null, 'Order {{order_no}}: {{test_count}} test(s) at {{lab_name}} are past their turnaround time.'],
        ],
        'samples_delayed' => [
            'sms' => [null, 'Manifest {{manifest_no}} ({{from_branch}} to {{to_branch}}): {{sample_count}} sample(s) in transit past their stability limit.'],
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
