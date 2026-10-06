<?php

namespace App\Modules\Locker\Listeners;

use App\Modules\Lab\Events\ReportPdfViewed;
use App\Modules\Locker\Enums\AccessAction;
use App\Modules\Locker\Enums\AccessActorType;
use App\Modules\Locker\Services\RecordAccessLogger;
use App\Modules\Locker\Services\ReleasedReportBridge;
use App\Modules\Shared\Jobs\WithSystemScope;

/**
 * A report PDF opened by staff or through a signed link is a view of the
 * patient's health record (spec §10.7): it goes to record_access_logs. If
 * the locker copy is not there yet (the release job is still queued), it is
 * made now, so no view goes unrecorded.
 */
final class LogReportPdfView
{
    public function __construct(
        private readonly ReleasedReportBridge $bridge,
        private readonly RecordAccessLogger $accessLogger,
    ) {}

    public function handle(ReportPdfViewed $event): void
    {
        WithSystemScope::run($event->organizationId, function () use ($event): void {
            $recordId = $this->bridge->copy($event->organizationId, $event->reportId);

            if ($recordId === null) {
                return;
            }

            [$actorType, $actorId] = $event->via === 'staff'
                ? [AccessActorType::Staff, $event->viewerUserId]
                : [AccessActorType::ReportLink, null];

            $this->accessLogger->logFrom($recordId, $actorType, $actorId, AccessAction::Download, $event->ipAddress);
        });
    }
}
