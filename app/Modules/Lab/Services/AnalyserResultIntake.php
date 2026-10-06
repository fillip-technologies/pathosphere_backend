<?php

namespace App\Modules\Lab\Services;

use App\Modules\Booking\Services\OrderReporting;
use App\Modules\Catalogue\Services\TestDirectory;
use App\Modules\Lab\Enums\WorklistStatus;
use App\Modules\Lab\Models\InterfaceAgent;
use App\Modules\Lab\Models\WorklistEntry;
use App\Modules\Samples\Services\LabSamples;
use App\Modules\Shared\Errors\DomainError;

/**
 * Results uploaded by a lab's interface agent (spec §8, §11 offline
 * resilience). Values are matched to the lab's open tests by sample barcode
 * and test code. The agent may replay a buffer after an outage: values
 * already stored are reported as unchanged, never duplicated.
 *
 * Each test is saved on its own, so one bad value does not hold back the
 * rest of the upload; the outcome lists what was rejected and why.
 */
final class AnalyserResultIntake
{
    public function __construct(
        private readonly LabSamples $samples,
        private readonly OrderReporting $orders,
        private readonly TestDirectory $tests,
        private readonly ResultEntryService $entries,
    ) {}

    /**
     * @param  list<AnalyserResult>  $results
     * @return array{accepted: int, unchanged: int, rejected: list<array<string, mixed>>}
     */
    public function receive(InterfaceAgent $agent, array $results): array
    {
        $outcome = ['accepted' => 0, 'unchanged' => 0, 'rejected' => []];
        $groups = [];

        foreach ($results as $index => $result) {
            $groups[strtoupper($result->barcode).'|'.strtoupper($result->testCode)][$index] = $result;
        }

        foreach ($groups as $group) {
            $first = reset($group);
            $entry = $this->openEntry($agent, $first->barcode, $first->testCode);
            $problem = match (true) {
                $entry === null => ['TEST_NOT_AT_LAB', 'No open test with this barcode and test code at this lab.'],
                $first->runNo !== null && $first->runNo !== $entry->current_run => ['RUN_CLOSED', "Run {$first->runNo} is closed; the test is on run {$entry->current_run}."],
                default => null,
            };

            if ($problem !== null) {
                $this->reject($outcome, array_keys($group), $problem[0], $problem[1]);

                continue;
            }

            /** @var WorklistEntry $entry */
            try {
                $changes = $this->entries->enterFromAnalyser(
                    $entry,
                    array_values(array_map(fn (AnalyserResult $result) => new ResultInput($result->parameterCode, $result->value), $group)),
                    $first->instrument,
                    $first->measuredAt,
                );
                $outcome['accepted'] += $changes->created + $changes->updated;
                $outcome['unchanged'] += $changes->unchanged;
            } catch (DomainError $error) {
                $this->reject($outcome, array_keys($group), $error->errorCode, $error->getMessage());
            }
        }

        return $outcome;
    }

    private function openEntry(InterfaceAgent $agent, string $barcode, string $testCode): ?WorklistEntry
    {
        $sample = $this->samples->atLabByBarcode($agent->organization_id, $agent->branch_id, $barcode);

        if ($sample === null) {
            return null;
        }

        $testIds = array_map(fn ($item) => $item->testId, $this->orders->labItems($agent->organization_id, $sample->orderItemIds));
        $testId = null;

        foreach ($this->tests->resultDefinitions(array_values($testIds)) as $definition) {
            if (strcasecmp($definition->code, $testCode) === 0) {
                $testId = $definition->testId;
            }
        }

        return $testId === null ? null : WorklistEntry::query()
            ->where('sample_id', $sample->id)
            ->where('test_id', $testId)
            ->whereIn('status', [WorklistStatus::Pending, WorklistStatus::Entered, WorklistStatus::Verified])
            ->first();
    }

    /**
     * @param  array{accepted: int, unchanged: int, rejected: list<array<string, mixed>>}  $outcome
     * @param  list<int>  $indexes
     */
    private function reject(array &$outcome, array $indexes, string $code, string $message): void
    {
        foreach ($indexes as $index) {
            $outcome['rejected'][] = ['index' => $index, 'code' => $code, 'message' => $message];
        }
    }
}
