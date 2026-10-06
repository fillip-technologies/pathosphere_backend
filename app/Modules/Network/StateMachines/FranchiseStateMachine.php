<?php

namespace App\Modules\Network\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/**
 * Franchise lifecycle (spec §5.1): applied → kyc_pending → approved →
 * active ⇄ suspended, and any status → terminated.
 */
final class FranchiseStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'applied' => ['kyc_pending'],
            'kyc_pending' => ['approved'],
            'approved' => ['active'],
            'active' => ['suspended'],
            'suspended' => ['active'],
            self::ANY => ['terminated'],
        ];
    }

    protected function entityName(): string
    {
        return 'franchise';
    }
}
