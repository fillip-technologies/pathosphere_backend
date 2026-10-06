<?php

namespace App\Modules\Locker\Http\Resources;

use App\Modules\Locker\Domain\TrendPoint;
use App\Modules\Locker\Domain\TrendSeries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A parameter over time. The list shows the latest value only; one
 * parameter's trend adds every point, oldest first.
 *
 * @mixin TrendSeries
 */
final class TrendResource extends JsonResource
{
    public function __construct(TrendSeries $series, private readonly bool $withPoints)
    {
        parent::__construct($series);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $latest = $this->latest();

        return [
            'parameter_code' => $this->parameterCode,
            'parameter_name' => $this->parameterName(),
            'unit' => $this->unit(),
            'point_count' => count($this->points),
            'direction' => $this->direction(),
            'latest' => $latest === null ? null : self::point($latest),
            'points' => $this->when($this->withPoints, fn () => array_map(self::point(...), $this->points)),
        ];
    }

    /** @return array<string, mixed> */
    private static function point(TrendPoint $point): array
    {
        return [
            'record_date' => $point->recordDate,
            'medical_record_id' => $point->medicalRecordId,
            'value' => $point->value,
            'value_numeric' => $point->valueNumeric,
            'unit' => $point->unit,
            'reference_range' => $point->referenceRange,
            'flag' => $point->flag,
            'lab_name' => $point->labName,
        ];
    }
}
