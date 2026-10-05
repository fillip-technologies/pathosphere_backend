<?php

namespace App\Modules\Samples\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/**
 * Sample journey (spec §5.4). Two additions to the spec's table:
 *
 * - collected → received: a sample drawn at the lab that tests it is
 *   accessioned there without a manifest;
 * - received → in_transit: a lab that cannot run the tests re-routes the
 *   sample and forwards it on a new manifest (spec §5.4 step 6).
 *
 * Rejection is terminal; the redraw is a new sample.
 */
final class SampleStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'pending_collection' => ['collected'],
            'collected' => ['in_transit', 'received'],
            'in_transit' => ['received'],
            'received' => ['rejected', 'in_process', 'in_transit'],
            'in_process' => ['processed'],
            'processed' => ['stored'],
            'stored' => ['discarded'],
        ];
    }

    protected function entityName(): string
    {
        return 'sample';
    }
}
