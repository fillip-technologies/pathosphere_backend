<?php

namespace App\Modules\Shared\Notifications\Contracts;

use App\Modules\Shared\Notifications\SendReceipt;

/** SMS vendor boundary (MSG91, Gupshup, Kaleyra). Templates must be DLT-registered (spec §3). */
interface SmsSender
{
    public function sendSms(string $phone, string $body, ?string $dltTemplateId): SendReceipt;
}
