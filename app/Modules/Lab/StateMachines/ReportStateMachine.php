<?php

namespace App\Modules\Lab\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/**
 * Report lifecycle (spec §5.5): draft → pending_signature → signed →
 * released, or withheld for unpaid B2B dues until released.
 *
 * Before release a report goes back to draft when its content changes (a
 * rerun, or a test whose sample arrived later). A released version is only
 * ever superseded: it becomes `amended` when the next version is created.
 */
final class ReportStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'draft' => ['pending_signature'],
            'pending_signature' => ['draft', 'signed'],
            'signed' => ['draft', 'released', 'withheld'],
            'withheld' => ['draft', 'released'],
            'released' => ['amended'],
        ];
    }

    protected function entityName(): string
    {
        return 'report';
    }
}
