<?php

namespace App\Modules\Locker\Listeners;

use App\Modules\Lab\Events\ReportReleased;
use App\Modules\Locker\Services\ReleasedReportBridge;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Spec §9 ReportReleased: "create health-locker record". Queued after the
 * release commits; safe to retry.
 */
final class CopyReleasedReportToLocker implements ShouldQueue
{
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(private readonly ReleasedReportBridge $bridge) {}

    public function handle(ReportReleased $event): void
    {
        WithSystemScope::run($event->organizationId, fn () => $this->bridge->copy($event->organizationId, $event->reportId));
    }
}
