<?php

namespace App\Modules\Shared\Notifications\Contracts;

use App\Modules\Shared\Notifications\SendReceipt;

/** Email vendor boundary (SES, SendGrid, Postmark). */
interface EmailSender
{
    public function sendEmail(string $email, string $subject, string $body): SendReceipt;
}
