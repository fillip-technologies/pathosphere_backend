<?php

namespace App\Modules\Locker\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/** A reminder waits until it is sent, or the patient dismisses it first. */
final class MedicalReminderStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'pending' => ['sent', 'dismissed'],
        ];
    }

    protected function entityName(): string
    {
        return 'medical_reminder';
    }
}
