<?php

namespace App\Modules\Network\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/** B2B client account: active ⇄ on_hold → closed. On hold or closed, it cannot book. */
final class B2bClientStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'active' => ['on_hold', 'closed'],
            'on_hold' => ['active', 'closed'],
        ];
    }

    protected function entityName(): string
    {
        return 'b2b_client';
    }
}
