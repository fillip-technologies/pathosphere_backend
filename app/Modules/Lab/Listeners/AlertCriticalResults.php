<?php

namespace App\Modules\Lab\Listeners;

use App\Modules\Lab\Events\ResultCritical;
use App\Modules\Lab\Services\CriticalAlerts;
use App\Modules\Shared\Jobs\WithSystemScope;

/**
 * Runs synchronously right after the results commit (BUILD_PLAN Phase 5:
 * on shared hosting a queue may lag); the messages themselves go out on the
 * critical queue.
 */
final class AlertCriticalResults
{
    public function __construct(private readonly CriticalAlerts $alerts) {}

    public function handle(ResultCritical $event): void
    {
        WithSystemScope::run($event->organizationId, fn () => $this->alerts->alert($event->worklistEntryId, $event->resultIds));
    }
}
