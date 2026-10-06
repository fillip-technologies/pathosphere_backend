<?php

namespace App\Modules\Locker\Services;

use App\Modules\Booking\Services\PatientProfile;
use App\Modules\Booking\Services\PeopleDirectory;
use App\Modules\Locker\Enums\AccessAction;
use App\Modules\Locker\Enums\AccessActorType;
use App\Modules\Locker\Enums\ShareTarget;
use App\Modules\Locker\Errors\LockerError;
use App\Modules\Locker\Models\MedicalRecord;
use App\Modules\Locker\Models\RecordShare;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Records seen by someone the patient shared them with: a signed-in doctor,
 * or anyone holding a share link. A share counts only while it and its
 * consent are in force (spec §7.11); otherwise the record does not exist for
 * them. Every view and download is logged.
 */
final class SharedRecords
{
    public function __construct(
        private readonly PeopleDirectory $people,
        private readonly RecordFiles $files,
        private readonly RecordAccessLogger $accessLogger,
    ) {}

    /** @return Builder<RecordShare> the doctor's usable shares, newest first */
    public function forDoctor(string $doctorId): Builder
    {
        return RecordShare::query()
            ->usable(CarbonImmutable::now())
            ->where('shared_with_type', ShareTarget::Doctor)
            ->where('shared_with_ref', $doctorId)
            ->with(['consent', 'record' => fn ($records) => $records->with(LockerRecords::WITH)])
            ->orderByDesc('created_at');
    }

    public function doctorShare(string $doctorId, string $recordId): RecordShare
    {
        return $this->forDoctor($doctorId)->where('medical_record_id', $recordId)->first()
            ?? throw LockerError::recordNotFound();
    }

    /** The share behind a link token; a revoked, expired or unknown token is a 404. */
    public function linkShare(string $token): RecordShare
    {
        return RecordShare::query()
            ->usable(CarbonImmutable::now())
            ->whereIn('shared_with_type', [ShareTarget::Link, ShareTarget::Email])
            ->where('access_token_hash', RecordShare::hashToken($token))
            ->with(['consent', 'record' => fn ($records) => $records->with(LockerRecords::WITH)])
            ->first() ?? throw LockerError::recordNotFound();
    }

    /** Who the record is about: identity only, never contact details. */
    public function patientOf(MedicalRecord $record): ?PatientProfile
    {
        return $this->people->patient($record->patient_id);
    }

    public function logView(RecordShare $share, AccessActorType $actorType, string $actorId): void
    {
        $this->accessLogger->log([$share->medical_record_id], $actorType, $actorId, AccessAction::View);
    }

    public function download(RecordShare $share, AccessActorType $actorType, string $actorId, ?int $version): StreamedResponse
    {
        $record = $share->record;
        $organizationId = $this->patientOf($record)->organizationId ?? throw LockerError::recordNotFound();
        $response = $this->files->download($record, $organizationId, $version);
        $this->accessLogger->log([$record->id], $actorType, $actorId, AccessAction::Download);

        return $response;
    }
}
