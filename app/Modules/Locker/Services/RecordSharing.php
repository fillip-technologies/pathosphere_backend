<?php

namespace App\Modules\Locker\Services;

use App\Modules\Booking\Services\PeopleDirectory;
use App\Modules\Locker\Enums\AccessAction;
use App\Modules\Locker\Enums\AccessActorType;
use App\Modules\Locker\Enums\ConsentPurpose;
use App\Modules\Locker\Enums\ConsentStatus;
use App\Modules\Locker\Enums\ShareStatus;
use App\Modules\Locker\Enums\ShareTarget;
use App\Modules\Locker\Errors\LockerError;
use App\Modules\Locker\Models\Consent;
use App\Modules\Locker\Models\MedicalRecord;
use App\Modules\Locker\Models\RecordShare;
use App\Modules\Locker\StateMachines\ConsentStateMachine;
use App\Modules\Locker\StateMachines\RecordShareStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\NotificationService;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A patient shares records with a doctor, an email address or anyone
 * holding a link (spec §7.11). Each share is one consent, granted by the
 * patient for a purpose and a number of days, and one record_shares row per
 * record. Revoking the consent ends every share at once (spec §10 ABDM rule:
 * honour revocation immediately).
 */
final class RecordSharing
{
    public function __construct(
        private readonly PeopleDirectory $people,
        private readonly ConsentStateMachine $consentStates,
        private readonly RecordShareStateMachine $shareStates,
        private readonly RecordAccessLogger $accessLogger,
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notifications,
        private readonly CurrentScope $currentScope,
    ) {}

    /** @return Builder<Consent> the profile's own shares (not ABDM consents), newest first */
    public function list(PatientViewer $viewer): Builder
    {
        return Consent::query()
            ->whereIn('patient_id', $viewer->patientIds())
            ->whereNull('consent_artefact_id')
            ->with('shares')
            ->orderByDesc('created_at');
    }

    public function find(PatientViewer $viewer, string $consentId): Consent
    {
        // ABDM consents are revoked in the patient's ABHA app, not here.
        return Consent::query()
            ->whereKey($consentId)
            ->whereIn('patient_id', $viewer->patientIds())
            ->whereNull('consent_artefact_id')
            ->with('shares')
            ->first() ?? throw LockerError::notFound('share');
    }

    /**
     * @param  list<string>  $recordIds
     * @param  array{type: string, doctor_phone?: string|null, email?: string|null}  $sharedWith
     */
    public function share(PatientViewer $viewer, array $recordIds, array $sharedWith, ConsentPurpose $purpose, int $days): CreatedShare
    {
        $records = $this->shareableRecords($viewer, $recordIds);
        $target = ShareTarget::from($sharedWith['type']);
        $doctor = $target === ShareTarget::Doctor
            ? ($this->people->doctorWithPhone((string) $sharedWith['doctor_phone']) ?? throw LockerError::doctorNotRegistered())
            : null;
        [$requester, $reference] = match ($target) {
            ShareTarget::Doctor => [$doctor->name ?? '', $doctor->id ?? ''],
            ShareTarget::Email => [mb_strtolower((string) $sharedWith['email']), mb_strtolower((string) $sharedWith['email'])],
            ShareTarget::Link => ['Anyone with the link', 'link'],
        };
        $now = CarbonImmutable::now();
        $expiresAt = $now->addDays($days);
        $tokens = [];

        $consent = DB::transaction(function () use ($viewer, $records, $target, $requester, $reference, $purpose, $now, $expiresAt, &$tokens): Consent {
            $consent = new Consent([
                'requester' => mb_substr($requester, 0, 150),
                'purpose' => $purpose,
                'scope' => ['medical_record_ids' => array_keys($records)],
                'status' => ConsentStatus::Granted,
                'granted_at' => $now,
                'expires_at' => $expiresAt,
            ]);
            $consent->patient_id = $viewer->profile()->id;
            $consent->save();
            $this->auditLogger->recordCreated('consent.granted', $consent);

            foreach ($records as $recordId => $record) {
                $token = $target === ShareTarget::Doctor ? null : Str::random(48);
                $share = new RecordShare([
                    'shared_with_type' => $target,
                    'shared_with_ref' => $reference,
                    'expires_at' => $expiresAt,
                    'status' => ShareStatus::Active,
                ]);
                $share->medical_record_id = $recordId;
                $share->consent_id = $consent->id;
                $share->access_token_hash = $token === null ? null : RecordShare::hashToken($token);
                $share->save();

                if ($token !== null) {
                    $tokens[] = ['medical_record_id' => $recordId, 'title' => $record->title, 'token' => $token];
                }
            }

            $this->accessLogger->log(array_keys($records), AccessActorType::Patient, $viewer->holder()->id, AccessAction::Share);

            return $consent;
        });

        $links = array_map(fn (array $link) => $link + ['url' => url("/api/v1/shared-records/{$link['token']}")], $tokens);
        $this->announce($viewer, $consent, $target, $doctor?->id, count($records), $links);

        return new CreatedShare($consent->load('shares'), $target === ShareTarget::Link ? $links : []);
    }

    /** Ends the consent and every share under it, now. Revoking twice changes nothing. */
    public function revoke(PatientViewer $viewer, string $consentId): void
    {
        $consent = $this->find($viewer, $consentId);

        if ($consent->status !== ConsentStatus::Granted) {
            return;
        }

        DB::transaction(function () use ($viewer, $consent): void {
            $this->consentStates->transition($consent, ConsentStatus::Revoked, ['revoked_at' => CarbonImmutable::now()]);

            foreach ($consent->shares as $share) {
                if ($share->status === ShareStatus::Active) {
                    $this->shareStates->transition($share, ShareStatus::Revoked);
                }
            }

            $this->accessLogger->log($consent->shares->pluck('medical_record_id')->all(), AccessActorType::Patient, $viewer->holder()->id, AccessAction::Revoke);
        });
    }

    /**
     * The requested records, in order. Each must be one of the profile's
     * current records; every one that is not is reported.
     *
     * @param  list<string>  $recordIds
     * @return array<string, MedicalRecord>
     */
    private function shareableRecords(PatientViewer $viewer, array $recordIds): array
    {
        $found = MedicalRecord::query()->currentFor($viewer->patientIds())->whereKey($recordIds)->get()->keyBy('id');
        $missing = [];
        $records = [];

        foreach ($recordIds as $position => $recordId) {
            $record = $found->get($recordId);

            if ($record === null) {
                $missing[] = $position;

                continue;
            }

            $records[$recordId] = $record;
        }

        if ($missing !== []) {
            throw LockerError::recordsNotShareable($missing);
        }

        return $records;
    }

    /** @param  list<array{medical_record_id: string, title: string, token: string, url: string}>  $links */
    private function announce(PatientViewer $viewer, Consent $consent, ShareTarget $target, ?string $doctorId, int $recordCount, array $links): void
    {
        $variables = [
            'patient_name' => $viewer->profile()->name,
            'record_count' => (string) $recordCount,
            'expires_on' => $consent->expires_at?->setTimezone('Asia/Kolkata')->format('d M Y') ?? '',
            'consent_id' => $consent->id,
        ];

        $recipient = match ($target) {
            ShareTarget::Doctor => $doctorId === null ? null : $this->people->doctorRecipient($doctorId),
            ShareTarget::Email => new NotificationRecipient('email_share', $consent->id, $viewer->organizationId(), null, $consent->requester, false),
            ShareTarget::Link => null,
        };

        if ($recipient === null) {
            return;
        }

        $eventKey = $target === ShareTarget::Doctor ? 'records_shared_with_doctor' : 'records_shared_by_email';
        $variables += ['links' => implode("\n", array_map(fn (array $link) => "{$link['title']}: {$link['url']}", $links))];

        $this->currentScope->runAs(
            ScopeContext::system($viewer->organizationId()),
            fn () => $this->notifications->notify($eventKey, $recipient, $variables),
        );
    }
}
