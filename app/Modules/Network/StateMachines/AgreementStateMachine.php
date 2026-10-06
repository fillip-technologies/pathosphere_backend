<?php

namespace App\Modules\Network\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/**
 * Agreement lifecycle (spec §5.1): draft → sent_for_sign → active →
 * expired / terminated. A signature declined or left to expire at the
 * vendor sends the agreement back to draft, so it can be corrected and resent.
 */
final class AgreementStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'draft' => ['sent_for_sign'],
            'sent_for_sign' => ['active', 'draft'],
            'active' => ['expired', 'terminated'],
        ];
    }

    protected function entityName(): string
    {
        return 'franchise_agreement';
    }
}
