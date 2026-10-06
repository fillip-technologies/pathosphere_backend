<?php

namespace App\Modules\Lab\Listeners;

use App\Modules\Lab\Events\ReportReleased;
use App\Modules\Lab\Services\ReportPublisher;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Renders, stores and delivers a released report (spec §9). The spec gives
 * two minutes from release to the patient's phone, so it runs on the
 * critical queue.
 */
final class PublishReleasedReport implements ShouldQueue
{
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(private readonly ReportPublisher $publisher) {}

    public function viaQueue(): string
    {
        return 'critical';
    }

    public function handle(ReportReleased $event): void
    {
        WithSystemScope::run($event->organizationId, fn () => $this->publisher->publish($event->reportId));
    }
}
