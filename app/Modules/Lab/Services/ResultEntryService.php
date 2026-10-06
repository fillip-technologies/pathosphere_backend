<?php

namespace App\Modules\Lab\Services;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Services\OrderReporting;
use App\Modules\Booking\Services\ReportOrderFacts;
use App\Modules\Catalogue\Services\ParameterDefinition;
use App\Modules\Catalogue\Services\TestDirectory;
use App\Modules\Catalogue\Services\TestResultDefinition;
use App\Modules\Lab\Domain\FormulaEvaluator;
use App\Modules\Lab\Domain\ParsedResultValue;
use App\Modules\Lab\Domain\ResultFlagger;
use App\Modules\Lab\Domain\ResultValueParser;
use App\Modules\Lab\Enums\ResultSource;
use App\Modules\Lab\Enums\WorklistStatus;
use App\Modules\Lab\Errors\LabError;
use App\Modules\Lab\Events\ResultCritical;
use App\Modules\Lab\Models\LabResult;
use App\Modules\Lab\Models\WorklistEntry;
use App\Modules\Lab\StateMachines\WorklistEntryStateMachine;
use App\Modules\Samples\Services\LabSamples;
use App\Modules\Shared\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records results for a test at the lab (spec §5.5 step 1), typed by staff
 * or sent by the analyser interface. Each value is checked against its
 * parameter, flagged against the patient's reference range, and calculated
 * parameters are worked out from the others. Critical values raise an alert.
 *
 * Re-sending a value that is already stored changes nothing, so analyser
 * replays are safe (spec §8 interface agent: idempotent per order item,
 * parameter and run).
 */
final class ResultEntryService
{
    public function __construct(
        private readonly LabGuard $labGuard,
        private readonly OrderReporting $orders,
        private readonly TestDirectory $tests,
        private readonly LabSamples $samples,
        private readonly WorklistEntryStateMachine $entryStates,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param  list<ResultInput>  $inputs */
    public function enterByStaff(StaffContext $staff, WorklistEntry $entry, array $inputs): ResultChanges
    {
        $this->labGuard->assertActsFor($entry->processing_branch_id, 'enter results');

        return $this->enter($entry, $inputs, ResultSource::Manual, $staff->user()->id, null, CarbonImmutable::now());
    }

    /**
     * @param  list<ResultInput>  $inputs
     * @param  CarbonImmutable  $measuredAt  the analyser's own time, kept when results are replayed after an outage
     */
    public function enterFromAnalyser(WorklistEntry $entry, array $inputs, ?string $instrument, CarbonImmutable $measuredAt): ResultChanges
    {
        return $this->enter($entry, $inputs, ResultSource::Analyser, null, $instrument, $measuredAt);
    }

    /** @param  list<ResultInput>  $inputs */
    private function enter(WorklistEntry $entry, array $inputs, ResultSource $source, ?string $userId, ?string $instrument, CarbonImmutable $enteredAt): ResultChanges
    {
        $definition = $this->tests->resultDefinitions([$entry->test_id])[$entry->test_id];
        $values = $this->parse($definition, $inputs);
        $patient = $this->orders->reportFacts($entry->organization_id, $entry->order_id);

        return DB::transaction(function () use ($entry, $definition, $values, $patient, $source, $userId, $instrument, $enteredAt): ResultChanges {
            $entry = WorklistEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $stored = $this->currentResults($entry);

            if (! in_array($entry->status, [WorklistStatus::Pending, WorklistStatus::Entered], true)) {
                // A replay of what is already stored is harmless even after verification.
                if ($entry->status === WorklistStatus::Verified && $this->allStored($values, $stored)) {
                    return new ResultChanges(0, 0, count($values));
                }

                throw LabError::testNotOpen();
            }

            $created = $updated = $unchanged = 0;
            $critical = [];

            foreach ($values as $index => [$parameter, $value, $comment]) {
                $existing = $stored->get($parameter->id);

                if ($existing !== null && $existing->value === $value->value && $existing->comment === $comment) {
                    $unchanged++;

                    continue;
                }

                if ($existing?->isVerified()) {
                    throw LabError::invalidResults([[
                        'code' => 'RESULT_ALREADY_VERIFIED',
                        'message' => "{$parameter->name} is verified. Ask a supervisor for a rerun to change it.",
                        'field' => "results.{$index}.value",
                    ]]);
                }

                $result = $this->save($entry, $parameter, $value, $comment, $existing, $patient, $source, $userId, $instrument, $enteredAt);
                $stored->put($parameter->id, $result);
                $existing === null ? $created++ : $updated++;

                if ($result->is_critical) {
                    $critical[] = $result->id;
                }
            }

            foreach ($this->recalculate($entry, $definition, $stored, $patient, $enteredAt) as $result) {
                $stored->put($result->test_parameter_id, $result);

                if ($result->is_critical) {
                    $critical[] = $result->id;
                }
            }

            if ($created + $updated === 0) {
                return new ResultChanges(0, 0, $unchanged);
            }

            $this->updateEntryStatus($entry, $definition, $stored);
            $this->samples->markInProcess($entry->organization_id, $entry->sample_id);

            if ($critical !== []) {
                event(new ResultCritical($entry->id, $entry->organization_id, $critical));
            }

            return new ResultChanges($created, $updated, $unchanged);
        });
    }

    /**
     * Every problem is reported at once, one detail per value.
     *
     * @param  list<ResultInput>  $inputs
     * @return array<int, array{ParameterDefinition, ParsedResultValue, string|null}>
     */
    private function parse(TestResultDefinition $definition, array $inputs): array
    {
        $problems = [];
        $values = [];
        $seen = [];

        foreach ($inputs as $index => $input) {
            $parameter = $definition->parameterByCode($input->parameterCode);
            $field = "results.{$index}";

            $problem = match (true) {
                $parameter === null => ['UNKNOWN_PARAMETER', "{$definition->code} has no parameter {$input->parameterCode}."],
                $parameter->isCalculated() => ['CALCULATED_PARAMETER', "{$parameter->name} is calculated from the other results."],
                isset($seen[$parameter->id]) => ['DUPLICATE_PARAMETER', "{$parameter->name} appears more than once."],
                default => null,
            };

            if ($problem !== null) {
                $problems[] = ['code' => $problem[0], 'message' => $problem[1], 'field' => "{$field}.parameter_code"];

                continue;
            }

            $seen[$parameter->id] = true;

            try {
                $comment = $input->comment === null || trim($input->comment) === '' ? null : trim($input->comment);
                $values[$index] = [$parameter, ResultValueParser::parse($parameter->resultType, $input->value, $parameter->options), $comment];
            } catch (InvalidArgumentException $invalid) {
                $problems[] = ['code' => 'RESULT_VALUE_INVALID', 'message' => "{$parameter->name}: {$invalid->getMessage()}", 'field' => "{$field}.value"];
            }
        }

        if ($problems !== []) {
            throw LabError::invalidResults($problems);
        }

        return $values;
    }

    /**
     * @param  array<int, array{ParameterDefinition, ParsedResultValue, string|null}>  $values
     * @param  Collection<string, LabResult>  $stored
     */
    private function allStored(array $values, Collection $stored): bool
    {
        foreach ($values as [$parameter, $value, $comment]) {
            $existing = $stored->get($parameter->id);

            if ($existing === null || $existing->value !== $value->value || $existing->comment !== $comment) {
                return false;
            }
        }

        return true;
    }

    /** @return Collection<string, LabResult> by parameter ID */
    private function currentResults(WorklistEntry $entry): Collection
    {
        return LabResult::query()
            ->where('worklist_entry_id', $entry->id)
            ->where('run_no', $entry->current_run)
            ->get()
            ->keyBy('test_parameter_id')
            ->toBase();
    }

    /**
     * Works out calculated parameters whose inputs are all present. A changed
     * value needs verifying again.
     *
     * @param  Collection<string, LabResult>  $stored
     * @return list<LabResult> calculated results that were stored or changed
     */
    private function recalculate(WorklistEntry $entry, TestResultDefinition $definition, Collection $stored, ReportOrderFacts $patient, CarbonImmutable $at): array
    {
        $numericByCode = [];
        foreach ($definition->parameters as $parameter) {
            $numericByCode[strtoupper($parameter->code)] = $stored->get($parameter->id)?->value_numeric;
        }

        $changed = [];

        foreach ($definition->parameters as $parameter) {
            if (! $parameter->isCalculated() || $parameter->formula === null) {
                continue;
            }

            $computed = FormulaEvaluator::evaluate($parameter->formula, $numericByCode);

            if ($computed === null) {
                continue;
            }

            $value = ResultValueParser::formatCalculated($computed, $parameter->decimalPlaces);
            $existing = $stored->get($parameter->id);
            $numericByCode[strtoupper($parameter->code)] = $value->numeric;

            if ($existing !== null && $existing->value === $value->value) {
                continue;
            }

            $changed[] = $this->save($entry, $parameter, $value, null, $existing, $patient, ResultSource::Calculated, null, null, $at);
        }

        return $changed;
    }

    private function save(
        WorklistEntry $entry,
        ParameterDefinition $parameter,
        ParsedResultValue $value,
        ?string $comment,
        ?LabResult $existing,
        ReportOrderFacts $patient,
        ResultSource $source,
        ?string $userId,
        ?string $instrument,
        CarbonImmutable $enteredAt,
    ): LabResult {
        $range = $parameter->rangeFor($patient->gender, $patient->ageDays);
        $flag = ResultFlagger::flag($value->numeric, $range);
        $attributes = [
            'value' => $value->value,
            'value_numeric' => $value->numeric,
            'unit' => $parameter->unit,
            'ref_range_text' => $range?->printedText(),
            'flag' => $flag,
            'is_critical' => $flag?->isCritical() ?? false,
            'instrument' => $instrument,
            'source' => $source,
            'comment' => $comment,
            'entered_by' => $userId,
            'entered_at' => $enteredAt,
            'verified_by' => null,
            'verified_at' => null,
        ];

        if ($existing !== null) {
            $existing->forceFill($attributes)->save();
            $this->auditLogger->recordChanges('result.edit', $existing);

            return $existing;
        }

        $result = new LabResult;
        $result->forceFill($attributes + [
            'organization_id' => $entry->organization_id,
            'worklist_entry_id' => $entry->id,
            'processing_branch_id' => $entry->processing_branch_id,
            'sample_id' => $entry->sample_id,
            'order_item_id' => $entry->order_item_id,
            'test_parameter_id' => $parameter->id,
            'run_no' => $entry->current_run,
            'is_final' => true,
        ])->save();
        $this->auditLogger->recordCreated('result.enter', $result);

        return $result;
    }

    /**
     * Pending until every parameter that is not calculated has a value; then
     * waiting for verification.
     *
     * @param  Collection<string, LabResult>  $stored
     */
    private function updateEntryStatus(WorklistEntry $entry, TestResultDefinition $definition, Collection $stored): void
    {
        $missing = array_filter($definition->parameters, fn (ParameterDefinition $parameter) => ! $parameter->isCalculated() && ! $stored->has($parameter->id));
        $target = $missing === [] ? WorklistStatus::Entered : WorklistStatus::Pending;

        if ($entry->status === $target) {
            $entry->touch();

            return;
        }

        $this->entryStates->transition($entry, $target);
    }
}
