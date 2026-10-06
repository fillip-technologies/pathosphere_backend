<?php

namespace App\Modules\Ledger\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/** A top-up is paid once the gateway confirms it; nothing else changes it. */
final class WalletTopupStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return ['pending' => ['paid']];
    }

    protected function entityName(): string
    {
        return 'wallet_topup';
    }
}
