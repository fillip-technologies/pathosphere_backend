<?php

namespace App\Modules\Locker\Listeners;

use App\Modules\Lab\Events\ReportReleased;
use App\Modules\Locker\Services\CareContextLinker;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Spec §9 ReportReleased: "ABDM care-context link if ABHA linked". Queued
 * after the release commits; safe to retry.
 */
final class LinkReleasedReportToAbha implements ShouldQueue
{
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(private readonly CareContextLinker $linker) {}

    public function handle(ReportReleased $event): void
    {
        WithSystemScope::run($event->organizationId, fn () => $this->linker->reportReleased($event->organizationId, $event->reportId));
    }
}
