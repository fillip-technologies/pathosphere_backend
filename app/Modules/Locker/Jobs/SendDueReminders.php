<?php

namespace App\Modules\Locker\Jobs;

use App\Modules\Booking\Services\PeopleDirectory;
use App\Modules\Locker\Enums\ReminderStatus;
use App\Modules\Locker\Models\MedicalReminder;
use App\Modules\Locker\Services\ReminderService;
use App\Modules\Shared\Jobs\WithSystemScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Health-locker reminders job (spec §12 Phase 7): sends every reminder that
 * has come due. Quiet hours are applied by the notification service.
 */
final class SendDueReminders implements ShouldQueue
{
    use Queueable;

    public function handle(ReminderService $reminders, PeopleDirectory $people): void
    {
        MedicalReminder::query()
            ->where('status', ReminderStatus::Pending)
            ->where('remind_at', '<=', CarbonImmutable::now())
            // By ID, not offset: each reminder leaves the filter once sent.
            ->lazyById(200)
            ->each(function (MedicalReminder $reminder) use ($reminders, $people): void {
                $organizationId = $people->patient($reminder->patient_id)?->organizationId;

                if ($organizationId !== null) {
                    WithSystemScope::run($organizationId, fn () => $reminders->send($reminder->id));
                }
            });
    }
}
