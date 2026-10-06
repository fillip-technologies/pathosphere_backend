<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Domain\ReferenceRangeEntry;
use App\Modules\Catalogue\Models\Department;
use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Catalogue\Models\ReferenceRange;
use App\Modules\Catalogue\Models\TestParameter;

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

    /**
     * Parameters and reference ranges of tests, including tests and
     * parameters removed since they were ordered: results already being
     * worked on must still be reported.
     *
     * @param  list<string>  $testIds
     * @return array<string, TestResultDefinition> by test ID
     */
    public function resultDefinitions(array $testIds): array
    {
        return LabTest::query()
            ->withTrashed()
            ->with(['parameters' => fn ($parameters) => $parameters->withTrashed()->with('referenceRanges')])
            ->whereKey(array_values(array_unique($testIds)))
            ->get()
            ->mapWithKeys(fn (LabTest $test) => [$test->id => new TestResultDefinition(
                $test->id,
                $test->code,
                $test->name,
                $test->department_id,
                $test->method,
                $test->parameters->map(fn (TestParameter $parameter) => new ParameterDefinition(
                    $parameter->id,
                    $parameter->code,
                    $parameter->parameter_name,
                    $parameter->unit,
                    $parameter->result_type,
                    $parameter->decimal_places,
                    $parameter->options,
                    $parameter->formula,
                    $parameter->display_order,
                    $parameter->referenceRanges->map(fn (ReferenceRange $range) => new ReferenceRangeEntry(
                        $range->gender,
                        $range->age_min_days,
                        $range->age_max_days,
                        $range->ref_low,
                        $range->ref_high,
                        $range->critical_low,
                        $range->critical_high,
                        $range->display_text,
                    ))->values()->all(),
                    $parameter->loinc_code,
                ))->values()->all(),
                $test->loinc_code,
            )])
            ->all();
    }

    /**
     * @param  list<string>  $departmentIds
     * @return array<string, DepartmentFacts> by department ID, in report order
     */
    public function departments(array $departmentIds): array
    {
        return Department::query()
            ->withTrashed()
            ->whereKey(array_values(array_unique($departmentIds)))
            ->orderBy('report_order')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Department $department) => [$department->id => new DepartmentFacts(
                $department->id,
                $department->name,
                $department->signing_discipline,
                $department->report_order,
            )])
            ->all();
    }
}
