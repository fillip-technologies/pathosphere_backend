<?php

namespace App\Modules\Locker\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/** A shared record: active until revoked with its consent or past its expiry. */
final class RecordShareStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'active' => ['revoked', 'expired'],
        ];
    }

    protected function entityName(): string
    {
        return 'record_share';
    }
}
