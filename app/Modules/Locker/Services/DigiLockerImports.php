<?php

namespace App\Modules\Locker\Services;

use App\Modules\Locker\Contracts\DigiLocker;
use App\Modules\Locker\Contracts\DigiLocker\DigiLockerAccess;
use App\Modules\Locker\Contracts\DigiLocker\DigiLockerDocument;
use App\Modules\Locker\Enums\RecordSource;
use App\Modules\Locker\Errors\DigiLockerError;
use App\Modules\Locker\Models\ExternalHealthRecord;
use App\Modules\Locker\Models\MedicalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * DigiLocker pull into the health locker (spec §3, §12 Phase 9). The
 * patient connects their DigiLocker, sees the documents issued to them and
 * picks the ones to keep; each becomes a locker record of source
 * `digilocker` with its provenance in external_health_records. A document
 * goes into one locker once. A connection belongs to the patient who made it
 * and the profile they were viewing; anyone else gets 404.
 */
final class DigiLockerImports
{
    /** What the locker can store and show (spec §7.11 uploads). */
    private const FILE_EXTENSIONS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(
        private readonly DigiLocker $digiLocker,
        private readonly LockerRecords $records,
    ) {}

    public function start(PatientViewer $viewer): DigiLockerSession
    {
        $id = (string) Str::uuid();
        $state = Str::random(40);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $session = new DigiLockerSession(
            $id,
            $viewer->holder()->id,
            $viewer->profile()->id,
            $state,
            $verifier,
            $this->digiLocker->authorizationUrl($state, $challenge),
            CarbonImmutable::now()->addMinutes((int) config('pathology.digilocker.authorization_minutes')),
        );
        $this->remember($session);

        return $session;
    }

    public function find(PatientViewer $viewer, string $sessionId): DigiLockerSession
    {
        $stored = Cache::get($this->key($sessionId));
        $session = is_string($stored) ? DigiLockerSession::fromArray((array) json_decode(Crypt::decryptString($stored), true)) : null;

        if ($session === null || $session->holderId !== $viewer->holder()->id || $session->profileId !== $viewer->profile()->id || $session->expiresAt->isPast()) {
            throw DigiLockerError::sessionNotFound();
        }

        return $session;
    }

    /** The patient is back from DigiLocker with a code: exchange it for access. */
    public function authorize(PatientViewer $viewer, string $sessionId, string $code, string $state): DigiLockerSession
    {
        $session = $this->find($viewer, $sessionId);

        if ($session->status() === DigiLockerSession::CONNECTED) {
            throw DigiLockerError::alreadyAuthorized();
        }

        if (! hash_equals($session->state, $state)) {
            throw DigiLockerError::stateMismatch();
        }

        $access = $this->digiLocker->exchangeCode($code, $session->codeVerifier);
        $connectedUntil = min($access->expiresAt, CarbonImmutable::now()->addMinutes((int) config('pathology.digilocker.connection_minutes')));
        $connected = $session->connected($access->accessToken, $connectedUntil, $access->digiLockerId, $access->name);
        $this->remember($connected);

        return $connected;
    }

    /** @return list<DigiLockerListing> */
    public function documents(PatientViewer $viewer, string $sessionId): array
    {
        $documents = $this->digiLocker->issuedDocuments($this->access($this->find($viewer, $sessionId)));
        $importedHere = $this->importedRecordIds($viewer, array_map(fn (DigiLockerDocument $document) => $document->uri, $documents));

        return array_map(fn (DigiLockerDocument $document) => new DigiLockerListing(
            $document,
            $this->extensionFor($document->mimeTypes) !== null,
            $importedHere[$document->uri] ?? null,
        ), $documents);
    }

    /**
     * Copies one document into the profile's locker.
     *
     * @return array{MedicalRecord, bool} the record, and whether it was added now (false: it was already here)
     */
    public function import(PatientViewer $viewer, string $sessionId, string $uri, ?string $categoryCode, ?string $title): array
    {
        $access = $this->access($this->find($viewer, $sessionId));

        $existing = $this->existingImport($viewer, $uri);
        if ($existing !== null) {
            return [$existing, false];
        }

        $document = $this->listed($access, $uri);
        $file = $this->digiLocker->file($access, $uri);
        $extension = $this->extensionFor([$file->mimeType]) ?? throw DigiLockerError::fileNotSupported();
        $maxKb = (int) config('pathology.locker.upload_max_kb');

        if (strlen($file->contents) > $maxKb * 1024) {
            throw DigiLockerError::fileTooLarge($maxKb);
        }

        try {
            $record = $this->records->importFromDigiLocker($viewer, $document, $file, $categoryCode ?? $this->categoryFor($document), $title ?? $document->name, $extension);
        } catch (UniqueConstraintViolationException) {
            // Imported by a parallel request a moment ago.
            return [$this->existingImport($viewer, $uri) ?? throw DigiLockerError::importedElsewhere(), false];
        }

        return [$record, true];
    }

    /** The record this document already became in the profile's locker; another locker's copy is a conflict. */
    private function existingImport(PatientViewer $viewer, string $uri): ?MedicalRecord
    {
        $provenance = ExternalHealthRecord::query()->where('source', RecordSource::Digilocker)->where('external_id', $uri)->first();

        if ($provenance === null) {
            return null;
        }

        if (! $viewer->owns($provenance->patient_id)) {
            throw DigiLockerError::importedElsewhere();
        }

        return $this->records->find($viewer, $provenance->medical_record_id);
    }

    /** Only documents DigiLocker lists for this account can be fetched. */
    private function listed(DigiLockerAccess $access, string $uri): DigiLockerDocument
    {
        foreach ($this->digiLocker->issuedDocuments($access) as $document) {
            if ($document->uri === $uri) {
                return $document;
            }
        }

        throw DigiLockerError::documentNotFound();
    }

    /**
     * @param  list<string>  $uris
     * @return array<string, string> record ID by DigiLocker URI, for this profile
     */
    private function importedRecordIds(PatientViewer $viewer, array $uris): array
    {
        return ExternalHealthRecord::query()
            ->where('source', RecordSource::Digilocker)
            ->whereIn('external_id', $uris)
            ->whereIn('patient_id', $viewer->patientIds())
            ->pluck('medical_record_id', 'external_id')
            ->all();
    }

    private function categoryFor(DigiLockerDocument $document): string
    {
        /** @var array<string, string> $byDocType */
        $byDocType = config('pathology.digilocker.category_by_doc_type');

        return $byDocType[(string) $document->docType] ?? 'other';
    }

    /** @param  list<string>  $mimeTypes */
    private function extensionFor(array $mimeTypes): ?string
    {
        foreach ($mimeTypes as $mimeType) {
            $extension = self::FILE_EXTENSIONS[strtolower(trim(explode(';', $mimeType)[0]))] ?? null;

            if ($extension !== null) {
                return $extension;
            }
        }

        return null;
    }

    private function access(DigiLockerSession $session): DigiLockerAccess
    {
        if ($session->accessToken === null) {
            throw DigiLockerError::notAuthorized();
        }

        return new DigiLockerAccess($session->accessToken, $session->expiresAt, (string) $session->digiLockerId, $session->accountName);
    }

    private function remember(DigiLockerSession $session): void
    {
        Cache::put($this->key($session->id), Crypt::encryptString((string) json_encode($session->toArray())), $session->expiresAt);
    }

    private function key(string $sessionId): string
    {
        return 'digilocker-session:'.$sessionId;
    }
}
