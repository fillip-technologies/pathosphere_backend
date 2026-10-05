<?php

namespace App\Modules\Shared\Notifications;

enum NotificationChannel: string
{
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Email = 'email';
    case Push = 'push';
}
