<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Models\LabTest;

/** Read-only catalogue facts other modules need about tests. */
final class TestDirectory
{
    /**
     * Includes tests deactivated or removed since they were ordered: orders
     * already placed must still be collected.
     *
     * @param  list<string>  $testIds
     * @return array<string, SampleRequirement> by test ID
     */
    public function sampleRequirements(array $testIds): array
    {
        return LabTest::query()
            ->withTrashed()
            ->whereKey(array_values(array_unique($testIds)))
            ->get()
            ->mapWithKeys(fn (LabTest $test) => [$test->id => new SampleRequirement(
                $test->id,
                $test->code,
                $test->name,
                $test->short_name ?? $test->code,
                $test->sample_type,
                $test->container_type,
                $test->stability_hours,
            )])
            ->all();
    }
}
