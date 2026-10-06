<?php

namespace App\Modules\Lab\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/**
 * A test at the lab: pending until every parameter has a result, entered
 * until a supervisor verifies them, verified until a rerun reopens it.
 * Withdrawn when its sample leaves the lab unworked (re-routed or rejected).
 */
final class WorklistEntryStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'pending' => ['entered', 'withdrawn'],
            'entered' => ['verified', 'pending', 'withdrawn'],
            'verified' => ['pending'],
        ];
    }

    protected function entityName(): string
    {
        return 'worklist_entry';
    }
}
