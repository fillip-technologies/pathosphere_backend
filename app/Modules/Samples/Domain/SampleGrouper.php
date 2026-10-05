<?php

namespace App\Modules\Samples\Domain;

/**
 * Decides which containers to draw for an order (spec §5.4: one sample per
 * container, not per test). Tests share a container when they need the same
 * container and sample type and go to the same lab; a tube cannot travel to
 * two labs, so different labs always get separate containers.
 */
final class SampleGrouper
{
    /**
     * @param  list<SamplingLine>  $lines
     * @return list<PlannedSample> in the order the lines first need them
     */
    public static function group(array $lines): array
    {
        /** @var array<string, list<SamplingLine>> $groups */
        $groups = [];

        foreach ($lines as $line) {
            $key = implode("\0", [$line->processingBranchId, mb_strtolower($line->containerType), mb_strtolower($line->sampleType)]);
            $groups[$key][] = $line;
        }

        return array_values(array_map(fn (array $group): PlannedSample => new PlannedSample(
            $group[0]->processingBranchId,
            $group[0]->sampleType,
            $group[0]->containerType,
            array_map(fn (SamplingLine $line) => $line->orderItemId, $group),
            array_values(array_unique(array_map(fn (SamplingLine $line) => $line->testId, $group))),
        ), $groups));
    }
}
