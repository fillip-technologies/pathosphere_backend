<?php

namespace App\Modules\Samples\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/** Stock transfer: requested → dispatched → received, or cancelled before receipt. */
final class StockTransferStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'requested' => ['dispatched', 'cancelled'],
            'dispatched' => ['received', 'cancelled'],
        ];
    }

    protected function entityName(): string
    {
        return 'stock_transfer';
    }
}
