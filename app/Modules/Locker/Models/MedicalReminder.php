<?php

namespace App\Modules\Locker\Models;

use App\Modules\Locker\Enums\ReminderStatus;
use App\Modules\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;

/**
 * A follow-up test or vaccine reminder the patient set (spec §7.11), sent
 * by the reminders job when due.
 *
 * @property string $id
 * @property string $patient_id
 * @property string|null $medical_record_id
 * @property CarbonImmutable $remind_at
 * @property string $message
 * @property ReminderStatus $status
 * @property CarbonImmutable|null $sent_at
 */
final class MedicalReminder extends BaseModel
{
    protected function casts(): array
    {
        return [
            'remind_at' => 'immutable_datetime',
            'status' => ReminderStatus::class,
            'sent_at' => 'immutable_datetime',
        ];
    }
}
