<?php

namespace App\Modules\Network\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/**
 * KYC paper review (spec §5.1 step 2): the vendor check may auto-verify it,
 * then a person verifies or rejects it. Both outcomes are final; a rejected
 * paper is replaced by a new upload.
 */
final class FranchiseDocumentStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'uploaded' => ['auto_verified', 'verified', 'rejected'],
            'auto_verified' => ['verified', 'rejected'],
        ];
    }

    protected function entityName(): string
    {
        return 'franchise_document';
    }
}
