<?php

namespace App\Modules\Samples\Services;

use App\Modules\Booking\Services\OrderFulfilment;
use App\Modules\Booking\Services\SampleOrderFacts;
use App\Modules\Catalogue\Services\SampleRequirement;
use App\Modules\Catalogue\Services\TestDirectory;
use App\Modules\Samples\Domain\LabelContent;
use App\Modules\Samples\Domain\ZplLabel;
use App\Modules\Samples\Models\ManifestItem;
use App\Modules\Samples\Models\Sample;
use App\Modules\Samples\Models\SampleOrderItem;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Illuminate\Support\Str;

/**
 * Builds the full view of samples the caller can see: patient and order
 * identity, tests, label and every manifest leg (the trace of spec §12
 * Phase 4). The sample being visible is the proof of access, so its legs and
 * redraw are read at organization level: a PSC still sees where its sample
 * went after the clinical lab forwarded it.
 */
final class SampleDetails
{
    public function __construct(
        private readonly CurrentScope $currentScope,
        private readonly OrderFulfilment $orders,
        private readonly TestDirectory $tests,
    ) {}

    /** A visible sample by barcode (scanners) or by ID. */
    public function find(string $barcodeOrId): ?Sample
    {
        $column = Str::isUuid($barcodeOrId) ? 'id' : 'barcode';

        return Sample::query()->where($column, $barcodeOrId)->first();
    }

    /**
     * @param  iterable<Sample>  $samples  of one organization
     * @return list<SampleDetail>
     */
    public function describe(iterable $samples): array
    {
        $samples = collect($samples)->values();

        if ($samples->isEmpty()) {
            return [];
        }

        $organizationId = $samples->first()->organization_id;

        return $this->currentScope->runAs(ScopeContext::system($organizationId), function () use ($samples, $organizationId): array {
            $sampleIds = $samples->pluck('id')->all();
            $itemIdsBySample = SampleOrderItem::query()->whereIn('sample_id', $sampleIds)->orderBy('id')->get()->groupBy('sample_id');
            $testIdByItem = $this->orders->testIdsOfItems($organizationId, $itemIdsBySample->flatten()->pluck('order_item_id')->all());
            $requirements = $this->tests->sampleRequirements(array_values($testIdByItem));
            $orderFacts = $this->orders->factsForSamples($organizationId, $samples->pluck('order_id')->all());
            $journeys = ManifestItem::query()->with('manifest')->whereIn('sample_id', $sampleIds)->orderBy('id')->get()->groupBy('sample_id');
            $redraws = Sample::query()->whereIn('recollection_of_id', $sampleIds)->pluck('id', 'recollection_of_id');

            return $samples->map(function (Sample $sample) use ($itemIdsBySample, $testIdByItem, $requirements, $orderFacts, $journeys, $redraws): SampleDetail {
                $testsByItem = [];
                foreach ($itemIdsBySample->get($sample->id, collect()) as $link) {
                    $testsByItem[$link->order_item_id] = $requirements[$testIdByItem[$link->order_item_id]];
                }

                $order = $orderFacts[$sample->order_id];

                return new SampleDetail(
                    $sample,
                    $order,
                    $testsByItem,
                    $journeys->get($sample->id, collect())->values()->all(),
                    $redraws[$sample->id] ?? null,
                    $this->label($sample, $order, $testsByItem),
                );
            })->all();
        });
    }

    /** @param  array<string, SampleRequirement>  $testsByItem */
    private function label(Sample $sample, SampleOrderFacts $order, array $testsByItem): string
    {
        return ZplLabel::render(new LabelContent(
            $sample->barcode,
            $order->patientName,
            $order->ageAndGender(),
            $order->orderNo,
            $sample->container_type,
            array_values(array_unique(array_map(fn (SampleRequirement $test) => $test->labelName, $testsByItem))),
        ));
    }
}
