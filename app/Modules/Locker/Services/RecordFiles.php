<?php

namespace App\Modules\Locker\Services;

use App\Modules\Lab\Services\ReportFiles;
use App\Modules\Locker\Errors\LockerError;
use App\Modules\Locker\Models\MedicalDocument;
use App\Modules\Locker\Models\MedicalRecord;
use App\Modules\Shared\Files\PrivateFileStore;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The file behind a record. A lab report is the PDF the lab stored at
 * release (never a copy); an upload is the requested version, the newest by
 * default. Callers have already checked access and log the download.
 */
final class RecordFiles
{
    public function __construct(
        private readonly PrivateFileStore $files,
        private readonly ReportFiles $reportFiles,
    ) {}

    public function download(MedicalRecord $record, string $organizationId, ?int $version = null): StreamedResponse
    {
        if ($record->pathologyReport !== null) {
            return $this->reportFiles->downloadForLocker($organizationId, $record->pathologyReport->report_id);
        }

        $document = $version === null
            ? $record->documents->first()
            : $record->documents->first(fn (MedicalDocument $document) => $document->version === $version);

        if ($document === null) {
            throw $version === null ? LockerError::recordHasNoFile() : LockerError::documentVersionNotFound();
        }

        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);

        return $this->files->download($document->file_path, sprintf('record-%s-v%d.%s', $record->id, $document->version, $extension));
    }
}
