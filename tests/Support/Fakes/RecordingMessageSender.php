<?php

namespace Tests\Support\Fakes;

use App\Modules\Shared\Notifications\Contracts\EmailSender;
use App\Modules\Shared\Notifications\Contracts\SmsSender;
use App\Modules\Shared\Notifications\Contracts\WhatsAppSender;
use App\Modules\Shared\Notifications\SendReceipt;
use RuntimeException;

/** Records messages instead of sending them; can be told to fail a channel. */
final class RecordingMessageSender implements EmailSender, SmsSender, WhatsAppSender
{
    /** @var list<array{channel: string, to: string, body: string}> */
    public array $sent = [];

    /** @var list<string> channels that throw, to test fallback */
    public array $failingChannels = [];

    public function sendSms(string $phone, string $body, ?string $dltTemplateId): SendReceipt
    {
        return $this->record('sms', $phone, $body);
    }

    public function sendWhatsApp(string $phone, string $templateName, array $variables, string $renderedBody): SendReceipt
    {
        return $this->record('whatsapp', $phone, $renderedBody);
    }

    public function sendEmail(string $email, string $subject, string $body): SendReceipt
    {
        return $this->record('email', $email, $body);
    }

    /** @return list<string> */
    public function channelsUsed(): array
    {
        return array_column($this->sent, 'channel');
    }

    private function record(string $channel, string $to, string $body): SendReceipt
    {
        if (in_array($channel, $this->failingChannels, true)) {
            throw new RuntimeException("{$channel} vendor is down");
        }

        $this->sent[] = ['channel' => $channel, 'to' => $to, 'body' => $body];

        return new SendReceipt("msg-{$channel}-".count($this->sent));
    }
}
