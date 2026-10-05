<?php

namespace App\Modules\Booking\StateMachines;

use App\Modules\Shared\StateMachines\StateMachine;

/**
 * scheduled → assigned → en_route → collected → handed_over, with side exits
 * rescheduled, cancelled and failed (spec §5.3). A rescheduled visit is
 * assigned again for its new slot.
 */
final class HomeCollectionStateMachine extends StateMachine
{
    protected function transitions(): array
    {
        return [
            'scheduled' => ['assigned', 'rescheduled', 'cancelled', 'failed'],
            'assigned' => ['en_route', 'rescheduled', 'cancelled', 'failed'],
            'en_route' => ['collected', 'rescheduled', 'failed'],
            'collected' => ['handed_over'],
            'rescheduled' => ['assigned', 'cancelled'],
        ];
    }

    protected function entityName(): string
    {
        return 'home_collection';
    }
}
