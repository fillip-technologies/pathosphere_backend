<?php

namespace App\Modules\Shared\Notifications\Contracts;

use App\Modules\Shared\Notifications\SendReceipt;

/** WhatsApp vendor boundary (Meta Cloud API or a BSP). Uses Meta-approved templates (spec §3). */
interface WhatsAppSender
{
    /** @param  array<string, string>  $variables */
    public function sendWhatsApp(string $phone, string $templateName, array $variables, string $renderedBody): SendReceipt;
}
