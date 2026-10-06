<?php

namespace App\Modules\Locker\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/**
 * Consent lifecycle (spec §7.11). A patient's own share is granted when made;
 * `requested` and `denied` arrive with ABDM consent requests (Phase 8).
 * Revocation is final and takes effect at once (spec §10 ABDM rule).
 */
final class ConsentStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'requested' => ['granted', 'denied', 'expired'],
            'granted' => ['revoked', 'expired'],
        ];
    }

    protected function entityName(): string
    {
        return 'consent';
    }
}
