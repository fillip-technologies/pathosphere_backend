<?php

namespace App\Modules\Locker\Services;

use App\Modules\Locker\Enums\RecordSource;
use App\Modules\Locker\Errors\LockerError;
use App\Modules\Locker\Models\MedicalDocument;
use App\Modules\Locker\Models\MedicalRecord;
use App\Modules\Locker\Models\RecordCategory;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Files\PrivateFileStore;
use App\Modules\Shared\Files\PrivatePaths;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * A patient's own records (spec §7.11): the timeline, one record with its
 * results and file versions, and their uploads. Every record is reached
 * through the profile being viewed; another patient's record is a 404.
 */
final class LockerRecords
{
    /** What the API and the timeline load with every record. */
    public const WITH = ['category', 'pathologyReport.results', 'documents'];

    public function __construct(
        private readonly PrivateFileStore $files,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * The timeline: current records, newest first by default.
     *
     * @return Builder<MedicalRecord>
     */
    public function timeline(PatientViewer $viewer): Builder
    {
        return MedicalRecord::query()->currentFor($viewer->patientIds())->with(self::WITH);
    }

    /** One of the profile's records, including ones replaced by a corrected report. */
    public function find(PatientViewer $viewer, string $recordId): MedicalRecord
    {
        return MedicalRecord::query()
            ->whereKey($recordId)
            ->whereIn('patient_id', $viewer->patientIds())
            ->with(self::WITH)
            ->first() ?? throw LockerError::recordNotFound();
    }

    /**
     * A file the patient adds: prescription, discharge summary, scan… It is
     * stored under private/uploads/locker/{patient_id}/ (spec §3).
     *
     * @param  array{category: string, title: string, record_date: string, provider_facility?: string|null}  $details
     */
    public function upload(PatientViewer $viewer, array $details, UploadedFile $file): MedicalRecord
    {
        $categoryId = (string) RecordCategory::query()->where('code', $details['category'])->value('id');
        $patientId = $viewer->profile()->id;

        $record = DB::transaction(function () use ($details, $file, $categoryId, $patientId): MedicalRecord {
            $record = new MedicalRecord([
                'source' => RecordSource::Upload,
                'record_date' => $details['record_date'],
                'title' => $details['title'],
                'provider_facility' => $details['provider_facility'] ?? null,
            ]);
            $record->patient_id = $patientId;
            $record->category_id = $categoryId;
            $record->save();
            $this->auditLogger->recordCreated('medical_record.uploaded', $record);

            $this->storeDocument($record, $file, 1);

            return $record;
        });

        return $this->find($viewer, $record->id);
    }

    /** A newer scan or copy of an uploaded record. The earlier versions stay. */
    public function addVersion(PatientViewer $viewer, string $recordId, UploadedFile $file): MedicalRecord
    {
        $record = $this->find($viewer, $recordId);

        if ($record->source !== RecordSource::Upload) {
            throw LockerError::notAnUpload();
        }

        DB::transaction(function () use ($record, $file): void {
            // Serialise versions of one record.
            MedicalRecord::query()->whereKey($record->id)->lockForUpdate()->first();
            $next = (int) MedicalDocument::query()->where('medical_record_id', $record->id)->max('version') + 1;
            $this->storeDocument($record, $file, $next);
        });

        return $this->find($viewer, $record->id);
    }

    private function storeDocument(MedicalRecord $record, UploadedFile $file, int $version): void
    {
        $document = new MedicalDocument([
            'mime_type' => mb_substr((string) $file->getMimeType(), 0, 60),
            'version' => $version,
            'uploaded_at' => CarbonImmutable::now(),
        ]);
        $document->id = $document->newUniqueId();
        $document->medical_record_id = $record->id;

        $stored = $this->files->putNew(
            PrivatePaths::lockerUpload($record->patient_id, $document->id, (string) ($file->extension() ?: $file->getClientOriginalExtension())),
            (string) $file->get(),
        );
        $document->file_path = $stored->path;
        $document->checksum = $stored->sha256;
        $document->size_bytes = $stored->sizeBytes;
        $document->save();

        $this->auditLogger->record('medical_document.uploaded', $record, [], ['document_id' => $document->id, 'version' => $version]);
    }
}
