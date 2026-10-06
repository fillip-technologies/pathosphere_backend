<?php

namespace App\Modules\Locker\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/** An acknowledged health-information request ends transferred or failed. */
final class DataTransferStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'acknowledged' => ['transferred', 'failed'],
        ];
    }

    protected function entityName(): string
    {
        return 'abdm_data_transfer';
    }
}
