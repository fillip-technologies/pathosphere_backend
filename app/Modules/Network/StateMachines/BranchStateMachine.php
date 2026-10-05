<?php

namespace App\Modules\Network\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/** Branch lifecycle: setup → active ⇄ suspended → closed. */
final class BranchStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'setup' => ['active', 'closed'],
            'active' => ['suspended', 'closed'],
            'suspended' => ['active', 'closed'],
        ];
    }

    protected function entityName(): string
    {
        return 'branch';
    }
}
