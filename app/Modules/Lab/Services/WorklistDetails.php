<?php

namespace App\Modules\Lab\Services;

use App\Modules\Booking\Services\OrderFulfilment;
use App\Modules\Catalogue\Services\TestDirectory;
use App\Modules\Lab\Enums\WorklistStatus;
use App\Modules\Lab\Models\LabResult;
use App\Modules\Lab\Models\WorklistEntry;
use App\Modules\Samples\Services\LabSamples;
use App\Modules\Shared\Http\Concurrency\EntityTag;

/**
 * Builds worklist rows in one batch per page: patient identity, sample,
 * test parameters and the current run's results. Labs see who the patient
 * is, never how to contact them (spec §4 special rule).
 */
final class WorklistDetails
{
    public function __construct(
        private readonly OrderFulfilment $orders,
        private readonly TestDirectory $tests,
        private readonly LabSamples $samples,
    ) {}

    /** The caller's open test for an order item at their lab, if any. */
    public function liveEntryForItem(string $orderItemId): ?WorklistEntry
    {
        return WorklistEntry::query()
            ->where('order_item_id', $orderItemId)
            ->where('status', '!=', WorklistStatus::Withdrawn)
            ->first();
    }

    /**
     * @param  iterable<WorklistEntry>  $entries  of one organization
     * @return list<WorklistDetail>
     */
    public function describe(iterable $entries): array
    {
        $entries = collect($entries)->values();

        if ($entries->isEmpty()) {
            return [];
        }

        $organizationId = $entries->first()->organization_id;
        $orders = $this->orders->factsForSamples($organizationId, $entries->pluck('order_id')->all());
        $definitions = $this->tests->resultDefinitions($entries->pluck('test_id')->all());
        $samples = $this->samples->many($organizationId, $entries->pluck('sample_id')->all());
        $results = LabResult::query()->whereIn('worklist_entry_id', $entries->pluck('id'))->get()->groupBy('worklist_entry_id');

        return $entries->map(fn (WorklistEntry $entry) => new WorklistDetail(
            $entry,
            $orders[$entry->order_id],
            $samples[$entry->sample_id]->barcode,
            $samples[$entry->sample_id]->sampleType,
            $definitions[$entry->test_id],
            $results->get($entry->id, collect())
                ->where('run_no', $entry->current_run)
                ->keyBy('test_parameter_id')
                ->all(),
            EntityTag::for($entry),
        ))->all();
    }
}
