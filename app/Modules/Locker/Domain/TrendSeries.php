<?php

namespace App\Modules\Locker\Domain;

/**
 * A parameter's values over time across every lab and branch (spec §12
 * Phase 7: "trends across branches"), oldest first, with the direction of
 * the latest change. Pure: takes the points, returns the series.
 */
final class TrendSeries
{
    /** @param  list<TrendPoint>  $points  oldest first */
    private function __construct(
        public readonly string $parameterCode,
        public readonly array $points,
    ) {}

    /** @param  list<TrendPoint>  $points */
    public static function of(string $parameterCode, array $points): self
    {
        usort($points, fn (TrendPoint $a, TrendPoint $b) => [$a->recordDate, $a->medicalRecordId] <=> [$b->recordDate, $b->medicalRecordId]);

        return new self($parameterCode, $points);
    }

    public function latest(): ?TrendPoint
    {
        return $this->points === [] ? null : $this->points[array_key_last($this->points)];
    }

    /** The name and unit as last reported (catalogue wording can change over the years). */
    public function parameterName(): ?string
    {
        return $this->latest()?->parameterName;
    }

    public function unit(): ?string
    {
        return $this->latest()?->unit;
    }

    /**
     * 'up', 'down' or 'same' comparing the last two numeric values; null
     * with fewer than two, or when units differ (values not comparable).
     */
    public function direction(): ?string
    {
        $numeric = array_values(array_filter($this->points, fn (TrendPoint $point) => $point->valueNumeric !== null));

        if (count($numeric) < 2) {
            return null;
        }

        [$previous, $last] = array_slice($numeric, -2);

        if ($previous->unit !== $last->unit) {
            return null;
        }

        return match (bccomp((string) $last->valueNumeric, (string) $previous->valueNumeric, 4)) {
            1 => 'up',
            -1 => 'down',
            default => 'same',
        };
    }
}
