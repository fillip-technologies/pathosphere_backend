<?php

namespace App\Modules\Auth\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/** Staff account lifecycle: invited → active ⇄ disabled. */
final class UserStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'invited' => ['active', 'disabled'],
            'active' => ['disabled'],
            'disabled' => ['active'],
        ];
    }

    protected function entityName(): string
    {
        return 'user';
    }
}
