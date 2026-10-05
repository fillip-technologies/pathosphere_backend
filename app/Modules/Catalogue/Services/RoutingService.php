<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Domain\ProcessingBranchResolver;
use App\Modules\Catalogue\Domain\RoutingRuleEntry;
use App\Modules\Catalogue\Errors\CatalogueError;
use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Catalogue\Models\LabTestCapability;
use App\Modules\Catalogue\Models\RoutingRule;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Errors\DomainError;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Which lab runs which test (spec §7.4 capabilities and routing rules). */
final class RoutingService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NetworkDirectory $network,
    ) {}

    /**
     * Replaces the tests a lab can run (PUT /branches/{id}/capabilities).
     *
     * @param  list<array{test_id: string, is_active?: bool, daily_capacity?: int|null}>  $capabilities
     */
    public function replaceCapabilities(string $branchId, array $capabilities): int
    {
        if (! $this->network->labIsVisible($branchId)) {
            throw $this->network->branchIsVisible($branchId)
                ? CatalogueError::branchNotLab()
                : ValidationException::withMessages(['branch_id' => 'The selected branch does not exist.']);
        }

        $testIds = array_column($capabilities, 'test_id');
        $unknown = array_diff($testIds, LabTest::query()->whereKey($testIds)->pluck('id')->all());

        if ($unknown !== []) {
            throw ValidationException::withMessages(['capabilities' => 'Unknown tests: '.implode(', ', $unknown).'.']);
        }

        return DB::transaction(function () use ($branchId, $capabilities): int {
            $before = LabTestCapability::query()->where('branch_id', $branchId)->count();
            LabTestCapability::query()->where('branch_id', $branchId)->delete();

            foreach ($capabilities as $entry) {
                $capability = new LabTestCapability([
                    'test_id' => $entry['test_id'],
                    'is_active' => $entry['is_active'] ?? true,
                    'daily_capacity' => $entry['daily_capacity'] ?? null,
                ]);
                $capability->branch_id = $branchId;
                $capability->save();
            }

            $this->auditLogger->recordForBranch('lab_capabilities.replace', $branchId, ['count' => $before], ['count' => count($capabilities)]);

            return count($capabilities);
        });
    }

    /** @param  array<string, mixed>  $attributes */
    public function createRule(array $attributes): RoutingRule
    {
        $this->assertValidRule($attributes);

        return DB::transaction(function () use ($attributes): RoutingRule {
            $rule = new RoutingRule($attributes);
            $rule->save();
            $this->auditLogger->recordCreated('routing_rule.create', $rule);

            return $rule;
        });
    }

    /** @param  array<string, mixed>  $changes */
    public function updateRule(RoutingRule $rule, array $changes): RoutingRule
    {
        $this->assertValidRule($changes + $rule->only(['source_branch_id', 'test_id', 'processing_branch_id']));

        return DB::transaction(function () use ($rule, $changes): RoutingRule {
            $rule->fill($changes)->save();
            $this->auditLogger->recordChanges('routing_rule.update', $rule);

            return $rule;
        });
    }

    public function deleteRule(RoutingRule $rule): void
    {
        DB::transaction(function () use ($rule): void {
            $rule->delete();
            $this->auditLogger->record('routing_rule.delete', $rule, $rule->attributesToArray());
        });
    }

    /**
     * Where each test would be processed if booked at the branch now
     * (GET /routing-resolutions), using the same algorithm as booking.
     *
     * @param  list<string>  $testIds
     * @return array<string, string|null> test ID => processing lab ID, null when unroutable
     */
    public function resolve(string $branchId, array $testIds): array
    {
        $branch = $this->network->pricingProfile($branchId)
            ?? throw ValidationException::withMessages(['branch_id' => 'The selected branch does not exist.']);

        $rules = RoutingRule::query()->where('source_branch_id', $branchId)->where('is_active', true)->get()
            ->map(fn (RoutingRule $rule) => new RoutingRuleEntry($rule->test_id, $rule->processing_branch_id, $rule->priority))
            ->values()->all();

        $capable = [];
        LabTestCapability::query()
            ->whereIn('branch_id', $this->network->operatingLabIds($branch->organizationId))
            ->whereIn('test_id', $testIds)
            ->where('is_active', true)
            ->get(['branch_id', 'test_id'])
            ->each(function (LabTestCapability $capability) use (&$capable): void {
                $capable[$capability->branch_id][$capability->test_id] = true;
            });

        $resolved = [];
        foreach ($testIds as $testId) {
            $resolved[$testId] = ProcessingBranchResolver::resolve($branchId, $testId, $rules, $capable);
        }

        return $resolved;
    }

    /**
     * A rule must start at a visible branch and point at a visible lab; a
     * rule for one test needs that lab to have the test switched on
     * (spec §7.4).
     *
     * @param  array<string, mixed>  $rule
     */
    private function assertValidRule(array $rule): void
    {
        if (! $this->network->branchIsVisible($rule['source_branch_id'])) {
            throw ValidationException::withMessages(['source_branch_id' => 'The selected branch does not exist.']);
        }

        if (! $this->network->labIsVisible($rule['processing_branch_id'])) {
            throw ValidationException::withMessages(['processing_branch_id' => 'The processing branch must be a lab.']);
        }

        if ($rule['source_branch_id'] === $rule['processing_branch_id']) {
            throw ValidationException::withMessages(['processing_branch_id' => 'A branch already processes its own tests when it can; no rule is needed.']);
        }

        $testId = $rule['test_id'] ?? null;

        if ($testId === null) {
            return;
        }

        if (! LabTest::query()->whereKey($testId)->exists()) {
            throw ValidationException::withMessages(['test_id' => 'The selected test does not exist.']);
        }

        $capable = LabTestCapability::query()
            ->where('branch_id', $rule['processing_branch_id'])
            ->where('test_id', $testId)
            ->where('is_active', true)
            ->exists();

        if (! $capable) {
            throw new DomainError('LAB_CANNOT_RUN_TEST', 'The processing lab has no active capability for this test.', 422, [['field' => 'test_id']]);
        }
    }
}
