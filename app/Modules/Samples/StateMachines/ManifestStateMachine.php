<?php

namespace App\Modules\Samples\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/**
 * created → dispatched → received / partially_received (spec §5.4). A
 * partially received manifest becomes received when its last sample is
 * scanned; until then the missing-sample monitor watches it.
 */
final class ManifestStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'created' => ['dispatched'],
            'dispatched' => ['received', 'partially_received'],
            'partially_received' => ['received'],
        ];
    }

    protected function entityName(): string
    {
        return 'manifest';
    }
}
