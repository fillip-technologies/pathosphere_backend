<?php

namespace App\Modules\Locker\Http\Resources;

use App\Modules\Locker\Models\MedicalDocument;
use App\Modules\Locker\Models\MedicalRecord;
use App\Modules\Locker\Models\PathologyResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One record: lab results grouped by test, and every file version (newest
 * first) for uploads.
 *
 * @mixin MedicalRecord
 */
final class MedicalRecordDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...MedicalRecordResource::make($this->resource)->toArray($request),
            'results' => self::results($this->resource),
            'documents' => $this->documents->map(fn (MedicalDocument $document) => [
                'version' => $document->version,
                'mime_type' => $document->mime_type,
                'size_bytes' => $document->size_bytes,
                'checksum' => $document->checksum,
                'uploaded_at' => $document->uploaded_at->toIso8601ZuluString(),
            ])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function results(MedicalRecord $record): array
    {
        if ($record->pathologyReport === null) {
            return [];
        }

        return $record->pathologyReport->results
            ->groupBy('test_code')
            ->map(fn ($results) => [
                'test_code' => $results->first()->test_code,
                'test_name' => $results->first()->test_name,
                'parameters' => $results->map(fn (PathologyResult $result) => [
                    'code' => $result->parameter_code,
                    'name' => $result->parameter_name,
                    'value' => $result->value,
                    'value_numeric' => $result->value_numeric,
                    'unit' => $result->unit,
                    'reference_range' => $result->reference_range,
                    'flag' => $result->flag,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
