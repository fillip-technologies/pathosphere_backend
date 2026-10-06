<?php

namespace App\Modules\Locker\Services;

use App\Modules\Booking\Services\PeopleDirectory;
use App\Modules\Locker\Enums\ReminderStatus;
use App\Modules\Locker\Errors\LockerError;
use App\Modules\Locker\Models\MedicalRecord;
use App\Modules\Locker\Models\MedicalReminder;
use App\Modules\Locker\StateMachines\MedicalReminderStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Notifications\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up test and vaccine reminders (spec §7.11 medical_reminders), set
 * by the patient and sent by the reminders job when due.
 */
final class ReminderService
{
    public function __construct(
        private readonly MedicalReminderStateMachine $states,
        private readonly AuditLogger $auditLogger,
        private readonly PeopleDirectory $people,
        private readonly NotificationService $notifications,
    ) {}

    /** @return Builder<MedicalReminder> */
    public function list(PatientViewer $viewer): Builder
    {
        return MedicalReminder::query()->whereIn('patient_id', $viewer->patientIds());
    }

    public function find(PatientViewer $viewer, string $reminderId): MedicalReminder
    {
        return $this->list($viewer)->whereKey($reminderId)->first() ?? throw LockerError::notFound('reminder');
    }

    /** @param  array{remind_at: string, message: string, medical_record_id?: string|null}  $details */
    public function create(PatientViewer $viewer, array $details): MedicalReminder
    {
        $recordId = $details['medical_record_id'] ?? null;

        if ($recordId !== null && ! MedicalRecord::query()->whereKey($recordId)->whereIn('patient_id', $viewer->patientIds())->exists()) {
            throw LockerError::recordNotFound();
        }

        $reminder = new MedicalReminder([
            'remind_at' => CarbonImmutable::parse($details['remind_at'])->utc(),
            'message' => $details['message'],
            'status' => ReminderStatus::Pending,
        ]);
        $reminder->patient_id = $viewer->profile()->id;
        $reminder->medical_record_id = $recordId;
        $reminder->save();
        $this->auditLogger->recordCreated('medical_reminder.created', $reminder);

        return $reminder;
    }

    public function dismiss(PatientViewer $viewer, string $reminderId): MedicalReminder
    {
        $reminder = $this->find($viewer, $reminderId);
        $this->states->transition($reminder, ReminderStatus::Dismissed);

        return $reminder;
    }

    /**
     * Sends one due reminder (run by the job as the system). A reminder is
     * marked sent in the same transaction that queues its message, so it is
     * never sent twice.
     */
    public function send(string $reminderId): void
    {
        DB::transaction(function () use ($reminderId): void {
            $reminder = MedicalReminder::query()->lockForUpdate()->find($reminderId);

            if ($reminder === null || $reminder->status !== ReminderStatus::Pending) {
                return;
            }

            $recipient = $this->people->patientRecipient($reminder->patient_id);
            $patient = $this->people->patient($reminder->patient_id);

            if ($recipient !== null && $patient !== null) {
                $this->notifications->notify('medical_reminder', $recipient, [
                    'patient_name' => $patient->name,
                    'message' => $reminder->message,
                    'reminder_id' => $reminder->id,
                ]);
            }

            $this->states->transition($reminder, ReminderStatus::Sent, ['sent_at' => CarbonImmutable::now()]);
        });
    }
}
