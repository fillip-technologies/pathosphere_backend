<?php

namespace App\Modules\Shared\Notifications\Infrastructure;

use App\Modules\Shared\Notifications\Contracts\EmailSender;
use App\Modules\Shared\Notifications\Contracts\SmsSender;
use App\Modules\Shared\Notifications\Contracts\WhatsAppSender;
use App\Modules\Shared\Notifications\SendReceipt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Stand-in for the SMS, WhatsApp and email vendors until they are chosen and
 * DLT/Meta templates approved. Writes to the log (destinations are redacted
 * by the logging processor) and never sends anything.
 */
final class LogMessageSender implements EmailSender, SmsSender, WhatsAppSender
{
    public function sendSms(string $phone, string $body, ?string $dltTemplateId): SendReceipt
    {
        return $this->log('sms', ['phone' => $phone, 'dlt_template_id' => $dltTemplateId]);
    }

    public function sendWhatsApp(string $phone, string $templateName, array $variables, string $renderedBody): SendReceipt
    {
        return $this->log('whatsapp', ['phone' => $phone, 'template' => $templateName]);
    }

    public function sendEmail(string $email, string $subject, string $body): SendReceipt
    {
        return $this->log('email', ['email' => $email, 'subject' => $subject]);
    }

    /** @param  array<string, mixed>  $context */
    private function log(string $channel, array $context): SendReceipt
    {
        $messageId = 'log-'.Str::uuid();
        Log::info("Notification not sent: no {$channel} vendor configured.", $context + ['provider_message_id' => $messageId]);

        return new SendReceipt($messageId);
    }
}
