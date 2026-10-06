<?php

namespace App\Modules\Locker\Enums;

/** Follow-up reminder lifecycle (spec §7.11 medical_reminders.status). */
enum ReminderStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Dismissed = 'dismissed';
}
