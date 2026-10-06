<?php

namespace App\Modules\Locker\Services;

use App\Modules\Lab\Services\ReleasedReportCopy;
use App\Modules\Lab\Services\ReleasedReports;
use App\Modules\Locker\Enums\RecordSource;
use App\Modules\Locker\Models\MedicalRecord;
use App\Modules\Locker\Models\PathologyReport;
use App\Modules\Locker\Models\PathologyResult;
use App\Modules\Locker\Models\RecordCategory;
use App\Modules\Shared\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Puts each released report in the patient's health locker (spec §5.5 step
 * 5, §9 ReportReleased): a medical record, its pathology report and a copy
 * of every printed result, which the timeline and trend charts read.
 *
 * Safe to run any number of times for the same report. Each released
 * version gets its own record; when a corrected version arrives, the record
 * of the version it replaced is marked superseded and leaves the timeline.
 */
final class ReleasedReportBridge
{
    private const TITLE_PREFIX = 'Lab report: ';

    public function __construct(
        private readonly ReleasedReports $releasedReports,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @return string|null the record's ID; null when the report was never released */
    public function copy(string $organizationId, string $reportId): ?string
    {
        $existing = $this->recordIdFor($reportId);

        if ($existing !== null) {
            return $existing;
        }

        $copy = $this->releasedReports->lockerCopy($organizationId, $reportId);

        if ($copy === null) {
            return null;
        }

        try {
            $recordId = DB::transaction(fn (): string => $this->store($copy));
        } catch (UniqueConstraintViolationException) {
            // Another worker copied it at the same moment.
            return $this->recordIdFor($reportId);
        }

        $this->linkVersions($copy, $recordId);

        return $recordId;
    }

    private function store(ReleasedReportCopy $copy): string
    {
        $record = new MedicalRecord([
            'patient_id' => $copy->patientId,
            'source' => RecordSource::OwnLab,
            'record_date' => $copy->orderDate->toDateString(),
            'title' => mb_strimwidth(self::TITLE_PREFIX.implode(', ', $copy->testNames), 0, 200, '…'),
            'provider_facility' => $copy->labName,
        ]);
        $record->category_id = $this->labReportCategoryId();
        $record->save();

        $pathologyReport = new PathologyReport(['lab_name' => mb_substr($copy->labName, 0, 150)]);
        $pathologyReport->medical_record_id = $record->id;
        $pathologyReport->report_id = $copy->reportId;
        $pathologyReport->save();

        foreach ($copy->results as $result) {
            $line = new PathologyResult([
                'test_code' => $result->testCode,
                'test_name' => mb_substr($result->testName, 0, 150),
                'parameter_code' => $result->parameterCode,
                'parameter_name' => mb_substr($result->parameterName, 0, 150),
                'value' => $result->value,
                'value_numeric' => $result->valueNumeric,
                'unit' => $result->unit,
                'reference_range' => $result->referenceRange,
                'flag' => $result->flag,
            ]);
            $line->pathology_report_id = $pathologyReport->id;
            $line->save();
        }

        $this->auditLogger->record('medical_record.copied_from_report', $record, [], [
            'report_id' => $copy->reportId,
            'version' => $copy->version,
            'result_count' => count($copy->results),
        ]);

        return $record->id;
    }

    /** A corrected version replaces the one before it, whichever was copied first. */
    private function linkVersions(ReleasedReportCopy $copy, string $recordId): void
    {
        $previousRecordId = $copy->previousVersionReportId === null ? null : $this->recordIdFor($copy->previousVersionReportId);
        $nextRecordId = $copy->nextVersionReportId === null ? null : $this->recordIdFor($copy->nextVersionReportId);

        if ($previousRecordId !== null) {
            $this->supersede($previousRecordId, $recordId);
        }

        if ($nextRecordId !== null) {
            $this->supersede($recordId, $nextRecordId);
        }
    }

    private function supersede(string $recordId, string $byRecordId): void
    {
        $record = MedicalRecord::query()->find($recordId);

        if ($record === null || $record->superseded_by_id !== null) {
            return;
        }

        $record->superseded_by_id = $byRecordId;
        $record->save();
        $this->auditLogger->recordChanges('medical_record.superseded', $record);
    }

    private function recordIdFor(string $reportId): ?string
    {
        $recordId = PathologyReport::query()->where('report_id', $reportId)->value('medical_record_id');

        return $recordId === null ? null : (string) $recordId;
    }

    private function labReportCategoryId(): string
    {
        $categoryId = RecordCategory::query()->where('code', RecordCategory::LAB_REPORT)->value('id');

        return $categoryId === null
            ? throw new LogicException('Record categories are missing. Run the RecordCategorySeeder.')
            : (string) $categoryId;
    }
}
