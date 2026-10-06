<?php

namespace App\Modules\Locker\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/**
 * A care context's link to the patient's ABHA. A failed link is retried
 * (back to pending) or linked by the patient from their ABHA app; a linked
 * one stays linked.
 */
final class CareContextStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'pending' => ['linked', 'failed'],
            'failed' => ['pending', 'linked'],
        ];
    }

    protected function entityName(): string
    {
        return 'abdm_care_context';
    }

    protected function statusColumn(): string
    {
        return 'link_status';
    }
}
