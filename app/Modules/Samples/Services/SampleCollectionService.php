<?php

namespace App\Modules\Samples\Services;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Services\OrderFulfilment;
use App\Modules\Booking\Services\SamplingOrderLine;
use App\Modules\Catalogue\Services\TestDirectory;
use App\Modules\Samples\Domain\SampleGrouper;
use App\Modules\Samples\Domain\SamplingLine;
use App\Modules\Samples\Domain\StabilityChecker;
use App\Modules\Samples\Enums\SampleStatus;
use App\Modules\Samples\Errors\SampleError;
use App\Modules\Samples\Events\SampleCollected;
use App\Modules\Samples\Events\SampleReceived;
use App\Modules\Samples\Models\Sample;
use App\Modules\Samples\Models\SampleOrderItem;
use App\Modules\Samples\StateMachines\SampleStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Errors\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * From order to collected container (spec §5.4 steps 1–2): plan the
 * containers an order needs, label them, record the draw, and accession
 * samples drawn at the lab that tests them.
 */
final class SampleCollectionService
{
    public function __construct(
        private readonly OrderFulfilment $orders,
        private readonly TestDirectory $tests,
        private readonly SampleRegistrar $registrar,
        private readonly SampleStateMachine $sampleStates,
        private readonly BranchGuard $branchGuard,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Creates the containers the order still needs and returns all of its
     * samples. Safe to repeat: tests that already have a container are
     * skipped, so add-on tests get new containers and nothing is doubled.
     *
     * @return array{samples: Collection<int, Sample>, created: int}
     */
    public function prepareForOrder(string $orderId): array
    {
        $order = $this->orders->orderForSampling($orderId)
            ?? throw new DomainError(ErrorCode::NOT_FOUND, 'The requested resource was not found.', 404);

        if (! $order->isOpenForSampling) {
            throw SampleError::orderNotOpen();
        }

        return DB::transaction(function () use ($orderId): array {
            // Re-read under a lock: the booking listener and the desk may both ask.
            $order = $this->orders->orderForSampling($orderId, lockForUpdate: true)
                ?? throw new DomainError(ErrorCode::NOT_FOUND, 'The requested resource was not found.', 404);

            $created = 0;
            foreach (SampleGrouper::group($this->linesWithoutContainer($order->linesAwaitingCollection)) as $planned) {
                $this->registrar->register(
                    $order->organizationId,
                    $order->id,
                    $order->branchId,
                    $planned->processingBranchId,
                    $planned->sampleType,
                    $planned->containerType,
                    $planned->orderItemIds,
                );
                $created++;
            }

            return [
                'samples' => Sample::query()->where('order_id', $order->id)->orderBy('id')->get(),
                'created' => $created,
            ];
        });
    }

    /** Offline desks label tubes from pre-printed stock and tell us later (spec §11). */
    public function assignBarcode(Sample $sample, string $barcode): Sample
    {
        $this->branchGuard->assertActsFor($sample->collected_branch_id, 'label this sample');

        if ($sample->status !== SampleStatus::PendingCollection) {
            throw SampleError::barcodeLocked();
        }

        if ($sample->barcode === $barcode) {
            return $sample;
        }

        if ($this->registrar->barcodeIsTaken($sample->organization_id, $barcode)) {
            throw SampleError::barcodeInUse();
        }

        return DB::transaction(function () use ($sample, $barcode): Sample {
            $sample->update(['barcode' => $barcode]);
            $this->auditLogger->recordChanges('sample.barcode_assign', $sample);

            return $sample;
        });
    }

    /**
     * The container is drawn: its tests are collected, the order starts, and
     * the transit clock runs from the collection time (spec §5.4).
     */
    public function collect(StaffContext $staff, Sample $sample, ?CarbonImmutable $collectedAt): Sample
    {
        $this->branchGuard->assertActsFor($sample->current_branch_id, 'collect this sample');

        $facts = $this->orders->factsForSamples($sample->organization_id, [$sample->order_id])[$sample->order_id];
        if ($facts->isCancelled) {
            throw SampleError::orderCancelled();
        }

        $collectedAt ??= CarbonImmutable::now();
        $orderItemIds = $sample->orderItemIds();
        $requirements = $this->tests->sampleRequirements(array_values($this->orders->testIdsOfItems($sample->organization_id, $orderItemIds)));
        $stableUntil = StabilityChecker::stableUntil($collectedAt, array_values(array_map(fn ($requirement) => $requirement->stabilityHours, $requirements)));

        return DB::transaction(function () use ($staff, $sample, $collectedAt, $stableUntil, $orderItemIds): Sample {
            $this->sampleStates->transition($sample, SampleStatus::Collected, [
                'collected_by' => $staff->user()->id,
                'collection_datetime' => $collectedAt,
                'stable_until' => $stableUntil,
            ]);
            $this->orders->markItemsCollected($sample->organization_id, $sample->order_id, $orderItemIds);
            event(new SampleCollected($sample->id, $sample->organization_id));

            return $sample;
        });
    }

    /**
     * Accession at the lab for a sample drawn there (spec §5.4: collected →
     * received). Samples from elsewhere are received by scanning their manifest.
     */
    public function receiveAtLab(StaffContext $staff, Sample $sample): Sample
    {
        $this->branchGuard->assertActsFor($sample->processing_branch_id, 'accession this sample');

        if ($sample->current_branch_id !== $sample->processing_branch_id) {
            throw SampleError::needsManifest();
        }

        return DB::transaction(function () use ($staff, $sample): Sample {
            $this->sampleStates->transition($sample, SampleStatus::Received, [
                'received_at' => CarbonImmutable::now(),
                'received_by' => $staff->user()->id,
            ]);
            event(new SampleReceived($sample->id, $sample->organization_id));

            return $sample;
        });
    }

    /**
     * @param  list<SamplingOrderLine>  $lines
     * @return list<SamplingLine>
     */
    private function linesWithoutContainer(array $lines): array
    {
        $alreadyDrawn = SampleOrderItem::query()
            ->whereIn('order_item_id', array_map(fn (SamplingOrderLine $line) => $line->orderItemId, $lines))
            ->pluck('order_item_id')
            ->all();
        $pending = array_values(array_filter($lines, fn (SamplingOrderLine $line) => ! in_array($line->orderItemId, $alreadyDrawn, true)));
        $requirements = $this->tests->sampleRequirements(array_map(fn (SamplingOrderLine $line) => $line->testId, $pending));

        return array_map(fn (SamplingOrderLine $line) => new SamplingLine(
            $line->orderItemId,
            $line->testId,
            $line->processingBranchId,
            $requirements[$line->testId]->sampleType,
            $requirements[$line->testId]->containerType,
        ), $pending);
    }
}
