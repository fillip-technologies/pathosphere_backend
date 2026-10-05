<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Errors\CatalogueError;
use App\Modules\Catalogue\Models\Department;
use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Catalogue\Models\LabTestCapability;
use App\Modules\Catalogue\Models\Package;
use App\Modules\Catalogue\Models\PackageTest;
use App\Modules\Catalogue\Models\PriceListItem;
use App\Modules\Catalogue\Models\ReferenceRange;
use App\Modules\Catalogue\Models\RoutingRule;
use App\Modules\Catalogue\Models\TestParameter;
use App\Modules\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HQ-controlled catalogue: departments, tests with their parameters and
 * reference ranges, and packages (spec §7.4). Tests that were ever used are
 * deactivated, not deleted, so history keeps pointing at real rows.
 */
final class CatalogueService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param  array<string, mixed>  $attributes */
    public function createDepartment(string $organizationId, array $attributes): Department
    {
        return $this->createAudited(new Department($attributes), $organizationId, 'department.create');
    }

    /** @param  array<string, mixed>  $changes */
    public function updateDepartment(Department $department, array $changes): Department
    {
        return $this->updateAudited($department, $changes, 'department.update');
    }

    public function deleteDepartment(Department $department): void
    {
        if (LabTest::query()->where('department_id', $department->id)->exists()) {
            throw CatalogueError::inUse('department');
        }

        $this->deleteAudited($department, 'department.delete');
    }

    /** @param  array<string, mixed>  $attributes */
    public function createTest(string $organizationId, array $attributes): LabTest
    {
        $this->assertDepartmentExists($attributes['department_id']);

        return $this->createAudited(new LabTest($attributes), $organizationId, 'test.create');
    }

    /** @param  array<string, mixed>  $changes */
    public function updateTest(LabTest $test, array $changes): LabTest
    {
        if (array_key_exists('department_id', $changes)) {
            $this->assertDepartmentExists($changes['department_id']);
        }

        return $this->updateAudited($test, $changes, 'test.update');
    }

    public function deleteTest(LabTest $test): void
    {
        $inUse = PriceListItem::query()->where('test_id', $test->id)->exists()
            || PackageTest::query()->where('test_id', $test->id)->exists()
            || LabTestCapability::query()->where('test_id', $test->id)->exists()
            || RoutingRule::query()->where('test_id', $test->id)->exists();

        if ($inUse) {
            throw CatalogueError::inUse('test');
        }

        $this->deleteAudited($test, 'test.delete');
    }

    /**
     * @param  array<string, mixed>  $attributes  parameter fields plus optional `reference_ranges`
     */
    public function addParameter(LabTest $test, array $attributes): TestParameter
    {
        return DB::transaction(function () use ($test, $attributes): TestParameter {
            $parameter = new TestParameter(array_diff_key($attributes, ['reference_ranges' => true]));
            $parameter->test_id = $test->id;
            $parameter->save();
            $this->replaceRanges($parameter, $attributes['reference_ranges'] ?? []);
            $this->auditLogger->recordCreated('test_parameter.create', $parameter);

            return $parameter->load('referenceRanges');
        });
    }

    /**
     * Sending `reference_ranges` replaces all of the parameter's ranges.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateParameter(TestParameter $parameter, array $changes): TestParameter
    {
        return DB::transaction(function () use ($parameter, $changes): TestParameter {
            $parameter->fill(array_diff_key($changes, ['reference_ranges' => true]))->save();

            if (array_key_exists('reference_ranges', $changes)) {
                $this->replaceRanges($parameter, $changes['reference_ranges']);
            }

            $this->auditLogger->recordChanges('test_parameter.update', $parameter);

            return $parameter->load('referenceRanges');
        });
    }

    public function deleteParameter(TestParameter $parameter): void
    {
        DB::transaction(function () use ($parameter): void {
            $parameter->delete();
            $this->auditLogger->record('test_parameter.delete', $parameter);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  package fields plus `test_ids`
     */
    public function createPackage(string $organizationId, array $attributes): Package
    {
        $testIds = $this->existingTestIds($attributes['test_ids']);

        return DB::transaction(function () use ($organizationId, $attributes, $testIds): Package {
            $package = $this->createAudited(new Package(array_diff_key($attributes, ['test_ids' => true])), $organizationId, 'package.create');
            $package->tests()->sync($testIds);

            return $package->load('tests');
        });
    }

    /** @param  array<string, mixed>  $changes */
    public function updatePackage(Package $package, array $changes): Package
    {
        $testIds = array_key_exists('test_ids', $changes) ? $this->existingTestIds($changes['test_ids']) : null;

        return DB::transaction(function () use ($package, $changes, $testIds): Package {
            $before = $package->tests()->pluck('tests.id')->all();
            $this->updateAudited($package, array_diff_key($changes, ['test_ids' => true]), 'package.update');

            if ($testIds !== null) {
                $package->tests()->sync($testIds);
                $package->touch();
                $this->auditLogger->record('package.tests_change', $package, ['test_ids' => $before], ['test_ids' => $testIds]);
            }

            return $package->load('tests');
        });
    }

    public function deletePackage(Package $package): void
    {
        if (PriceListItem::query()->where('package_id', $package->id)->exists()) {
            throw CatalogueError::inUse('package');
        }

        $this->deleteAudited($package, 'package.delete');
    }

    /**
     * @param  list<array<string, mixed>>  $ranges
     */
    private function replaceRanges(TestParameter $parameter, array $ranges): void
    {
        $parameter->referenceRanges()->delete();

        foreach ($ranges as $range) {
            $referenceRange = new ReferenceRange($range);
            $referenceRange->test_parameter_id = $parameter->id;
            $referenceRange->save();
        }
    }

    private function assertDepartmentExists(string $departmentId): void
    {
        if (! Department::query()->whereKey($departmentId)->exists()) {
            throw ValidationException::withMessages(['department_id' => 'The selected department does not exist.']);
        }
    }

    /**
     * @param  list<string>  $testIds
     * @return list<string>
     */
    private function existingTestIds(array $testIds): array
    {
        $found = LabTest::query()->whereKey($testIds)->pluck('id')->all();
        $missing = array_values(array_diff($testIds, $found));

        if ($missing !== []) {
            throw ValidationException::withMessages(['test_ids' => 'Unknown tests: '.implode(', ', $missing).'.']);
        }

        return $found;
    }

    /**
     * @template TModel of Department|LabTest|Package
     *
     * @param  TModel  $model
     * @return TModel
     */
    private function createAudited(Department|LabTest|Package $model, string $organizationId, string $action): Department|LabTest|Package
    {
        return DB::transaction(function () use ($model, $organizationId, $action) {
            $model->organization_id = $organizationId;
            $model->save();
            $this->auditLogger->recordCreated($action, $model);

            return $model;
        });
    }

    /**
     * @template TModel of Department|LabTest|Package
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $changes
     * @return TModel
     */
    private function updateAudited(Department|LabTest|Package $model, array $changes, string $action): Department|LabTest|Package
    {
        return DB::transaction(function () use ($model, $changes, $action) {
            $model->fill($changes)->save();
            $this->auditLogger->recordChanges($action, $model);

            return $model;
        });
    }

    private function deleteAudited(Department|LabTest|Package $model, string $action): void
    {
        DB::transaction(function () use ($model, $action): void {
            $model->delete();
            $this->auditLogger->record($action, $model);
        });
    }
}
