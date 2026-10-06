<?php

namespace App\Modules\Ledger\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/** An export is built once: queued → ready, or failed (ask for a new one). */
final class AccountingExportStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return ['queued' => ['ready', 'failed']];
    }

    protected function entityName(): string
    {
        return 'accounting_export';
    }
}
