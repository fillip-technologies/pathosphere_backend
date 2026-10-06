<?php

namespace App\Modules\Locker\Services;

use App\Modules\Locker\Domain\TrendPoint;
use App\Modules\Locker\Domain\TrendSeries;
use App\Modules\Locker\Enums\AccessAction;
use App\Modules\Locker\Enums\AccessActorType;
use App\Modules\Locker\Models\PathologyResult;
use Illuminate\Support\Collection;

/**
 * A parameter's results over time, across every branch and lab that tested
 * the patient (spec §12 Phase 7). Only current report versions count. The
 * values come from the records they belong to, so reading a trend is logged
 * as a view of each of those records.
 */
final class HealthTrends
{
    public function __construct(private readonly RecordAccessLogger $accessLogger) {}

    /** @return list<TrendSeries> one per parameter code, by name */
    public function all(PatientViewer $viewer): array
    {
        $series = collect($this->points($viewer, null))
            ->groupBy(fn (array $row) => $row['code'])
            ->map(fn (Collection $rows, string $code) => TrendSeries::of($code, $rows->pluck('point')->all()))
            ->sortBy(fn (TrendSeries $series) => mb_strtolower((string) $series->parameterName()))
            ->values()
            ->all();

        $this->logViews($viewer, $series);

        return $series;
    }

    public function forParameter(PatientViewer $viewer, string $parameterCode): TrendSeries
    {
        $series = TrendSeries::of(mb_strtoupper($parameterCode), array_column($this->points($viewer, $parameterCode), 'point'));
        $this->logViews($viewer, [$series]);

        return $series;
    }

    /** @return list<array{code: string, point: TrendPoint}> */
    private function points(PatientViewer $viewer, ?string $parameterCode): array
    {
        return PathologyResult::query()
            ->join('pathology_reports', 'pathology_reports.id', '=', 'pathology_results.pathology_report_id')
            ->join('medical_records', 'medical_records.id', '=', 'pathology_reports.medical_record_id')
            ->whereIn('medical_records.patient_id', $viewer->patientIds())
            ->whereNull('medical_records.superseded_by_id')
            ->when($parameterCode !== null, fn ($query) => $query->where('pathology_results.parameter_code', $parameterCode))
            ->orderBy('medical_records.record_date')
            ->orderBy('pathology_results.id')
            ->get([
                'pathology_results.parameter_code',
                'pathology_results.parameter_name',
                'pathology_results.value',
                'pathology_results.value_numeric',
                'pathology_results.unit',
                'pathology_results.reference_range',
                'pathology_results.flag',
                'pathology_reports.lab_name',
                'medical_records.id as medical_record_id',
                'medical_records.record_date',
            ])
            ->map(fn (PathologyResult $row) => [
                'code' => mb_strtoupper($row->parameter_code),
                'point' => new TrendPoint(
                    substr((string) $row->getAttribute('record_date'), 0, 10),
                    (string) $row->getAttribute('medical_record_id'),
                    $row->parameter_name,
                    $row->value,
                    $row->value_numeric,
                    $row->unit,
                    $row->reference_range,
                    $row->flag,
                    (string) $row->getAttribute('lab_name'),
                ),
            ])
            ->values()
            ->all();
    }

    /** @param  list<TrendSeries>  $series */
    private function logViews(PatientViewer $viewer, array $series): void
    {
        $recordIds = [];

        foreach ($series as $one) {
            foreach ($one->points as $point) {
                $recordIds[] = $point->medicalRecordId;
            }
        }

        if ($recordIds !== []) {
            $this->accessLogger->log($recordIds, AccessActorType::Patient, $viewer->holder()->id, AccessAction::View);
        }
    }
}
