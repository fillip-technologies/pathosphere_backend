<?php

namespace App\Modules\Ledger\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/**
 * Settlement lifecycle (spec §5.6): draft → pending_approval → approved →
 * settled, with the side exit pending_approval → disputed → approved.
 */
final class SettlementStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'draft' => ['pending_approval'],
            'pending_approval' => ['approved', 'disputed'],
            'disputed' => ['approved'],
            'approved' => ['settled'],
        ];
    }

    protected function entityName(): string
    {
        return 'settlement';
    }
}
