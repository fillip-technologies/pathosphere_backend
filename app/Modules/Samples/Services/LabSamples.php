<?php

namespace App\Modules\Samples\Services;

use App\Modules\Samples\Enums\SampleStatus;
use App\Modules\Samples\Models\Sample;
use App\Modules\Samples\Models\SampleOrderItem;
use App\Modules\Samples\StateMachines\SampleStateMachine;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;

/**
 * What the lab needs from samples (spec §5.4 received → in_process →
 * processed). Callers hold a worklist entry, report or agent key for the
 * lab, which is the proof of access; samples are read at organization level.
 */
final class LabSamples
{
    public function __construct(
        private readonly CurrentScope $currentScope,
        private readonly SampleStateMachine $sampleStates,
    ) {}

    public function facts(string $organizationId, string $sampleId): ?LabSampleFacts
    {
        return $this->many($organizationId, [$sampleId])[$sampleId] ?? null;
    }

    /**
     * @param  list<string>  $sampleIds
     * @return array<string, LabSampleFacts> by sample ID
     */
    public function many(string $organizationId, array $sampleIds): array
    {
        return $this->inOrganization($organizationId, function () use ($sampleIds): array {
            $samples = Sample::query()->whereKey(array_values(array_unique($sampleIds)))->get();
            $itemIds = SampleOrderItem::query()->whereIn('sample_id', $samples->pluck('id'))->orderBy('id')->get()->groupBy('sample_id');

            return $samples->mapWithKeys(fn (Sample $sample) => [$sample->id => new LabSampleFacts(
                $sample->id,
                $sample->organization_id,
                $sample->order_id,
                $sample->barcode,
                $sample->sample_type,
                $sample->collected_branch_id,
                $sample->processing_branch_id,
                $sample->current_branch_id,
                $sample->status,
                $sample->collection_datetime,
                $sample->received_at,
                $itemIds->get($sample->id, collect())->pluck('order_item_id')->values()->all(),
            )])->all();
        });
    }

    /** A sample at the lab, by the barcode an analyser read. */
    public function atLabByBarcode(string $organizationId, string $labId, string $barcode): ?LabSampleFacts
    {
        $sampleId = $this->inOrganization($organizationId, fn () => Sample::query()
            ->where('barcode', $barcode)
            ->where('processing_branch_id', $labId)
            ->value('id'));

        return $sampleId === null ? null : $this->facts($organizationId, (string) $sampleId);
    }

    /** Results are being entered, first time or after a rerun: the sample is on the bench. */
    public function markInProcess(string $organizationId, string $sampleId): void
    {
        $this->moveTo($organizationId, $sampleId, [SampleStatus::Received, SampleStatus::Processed], SampleStatus::InProcess);
    }

    /** Every test on the sample is verified. */
    public function markProcessed(string $organizationId, string $sampleId): void
    {
        $this->moveTo($organizationId, $sampleId, [SampleStatus::InProcess], SampleStatus::Processed);
    }

    /** @param  list<SampleStatus>  $from */
    private function moveTo(string $organizationId, string $sampleId, array $from, SampleStatus $to): void
    {
        $this->inOrganization($organizationId, function () use ($sampleId, $from, $to): void {
            $sample = Sample::query()->lockForUpdate()->findOrFail($sampleId);

            if (in_array($sample->status, $from, true)) {
                $this->sampleStates->transition($sample, $to);
            }
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inOrganization(string $organizationId, callable $callback): mixed
    {
        return $this->currentScope->runAs(ScopeContext::system($organizationId), $callback);
    }
}
