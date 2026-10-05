<?php

namespace App\Modules\Samples\Services;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Samples\Domain\ManifestReceipt;
use App\Modules\Samples\Enums\ManifestItemCondition;
use App\Modules\Samples\Enums\ManifestStatus;
use App\Modules\Samples\Enums\SampleStatus;
use App\Modules\Samples\Errors\SampleError;
use App\Modules\Samples\Events\ManifestDispatched;
use App\Modules\Samples\Events\ManifestReceived;
use App\Modules\Samples\Models\Manifest;
use App\Modules\Samples\Models\ManifestItem;
use App\Modules\Samples\Models\Sample;
use App\Modules\Samples\StateMachines\ManifestStateMachine;
use App\Modules\Samples\StateMachines\SampleStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Numbering\NumberSequenceService;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transport bags between branches and labs (spec §5.4 steps 2–4). One open
 * manifest per route collects samples as they are drawn; the runner
 * dispatches it, and the receiving lab scans each barcode in.
 */
final class ManifestService
{
    private const DEFAULT_NUMBER_FORMAT = 'M{branch_code}-{seq:6}';

    /** Samples that may be bagged: drawn here, or received here and re-routed onward. */
    private const SHIPPABLE = [SampleStatus::Collected, SampleStatus::Received];

    public function __construct(
        private readonly CurrentScope $currentScope,
        private readonly NetworkDirectory $network,
        private readonly NumberSequenceService $sequences,
        private readonly AuditLogger $auditLogger,
        private readonly ManifestStateMachine $manifestStates,
        private readonly SampleStateMachine $sampleStates,
        private readonly SampleRejectionService $rejections,
        private readonly BranchGuard $branchGuard,
    ) {}

    /** @param  list<string>  $barcodes */
    public function create(string $organizationId, string $fromBranchId, string $toBranchId, array $barcodes): Manifest
    {
        $this->branchGuard->assertActsFor($fromBranchId, 'start a manifest from this branch');

        if ($fromBranchId === $toBranchId) {
            throw ValidationException::withMessages(['to_branch_id' => 'A manifest goes to another branch.']);
        }

        if (! $this->network->isOperatingLab($organizationId, $toBranchId)) {
            throw SampleError::destinationNotLab();
        }

        $openManifestId = $this->openManifestQuery($fromBranchId, $toBranchId)->value('id');
        if ($openManifestId !== null) {
            throw SampleError::openManifestExists($openManifestId);
        }

        $samples = $this->samplesByBarcode($barcodes);

        return DB::transaction(function () use ($organizationId, $fromBranchId, $toBranchId, $samples): Manifest {
            $manifest = $this->open($organizationId, $fromBranchId, $toBranchId);
            $this->bag($manifest, $samples);

            return $manifest;
        });
    }

    /** @param  list<string>  $barcodes */
    public function addSamples(Manifest $manifest, array $barcodes): Manifest
    {
        $this->branchGuard->assertActsFor($manifest->from_branch_id, 'change this manifest');
        $samples = $this->samplesByBarcode($barcodes);

        return DB::transaction(function () use ($manifest, $samples): Manifest {
            $this->lockOpen($manifest);
            $this->bag($manifest, $samples);

            return $manifest;
        });
    }

    public function removeSample(Manifest $manifest, string $sampleId): Manifest
    {
        $this->branchGuard->assertActsFor($manifest->from_branch_id, 'change this manifest');

        return DB::transaction(function () use ($manifest, $sampleId): Manifest {
            $this->lockOpen($manifest);
            $removed = ManifestItem::query()->where('transfer_id', $manifest->id)->where('sample_id', $sampleId)->delete();

            if ($removed === 0) {
                throw ValidationException::withMessages(['sample_id' => 'This sample is not on the manifest.']);
            }

            $this->auditLogger->record('manifest.sample_remove', $manifest, ['sample_id' => $sampleId]);

            return $manifest;
        });
    }

    /**
     * The runner takes the bag (spec §5.4 step 3): every sample goes in
     * transit, and the receiving lab is told what is coming.
     */
    public function dispatch(StaffContext $staff, Manifest $manifest, ?string $courierName, ?bool $temperatureOk, ?string $temperatureC): Manifest
    {
        $this->branchGuard->assertActsFor($manifest->from_branch_id, 'dispatch this manifest');

        return DB::transaction(function () use ($staff, $manifest, $courierName, $temperatureOk, $temperatureC): Manifest {
            $this->lockOpen($manifest);
            $items = ManifestItem::query()->with('sample')->where('transfer_id', $manifest->id)->get();

            if ($items->isEmpty()) {
                throw SampleError::manifestEmpty();
            }

            foreach ($items as $item) {
                $this->sampleStates->transition($item->sample, SampleStatus::InTransit);
            }

            $this->manifestStates->transition($manifest, ManifestStatus::Dispatched, [
                'dispatched_by' => $staff->user()->id,
                'dispatched_at' => CarbonImmutable::now(),
                'courier_name' => $courierName,
                'temperature_ok' => $temperatureOk,
                'dispatch_temp_c' => $temperatureC,
            ]);
            event(new ManifestDispatched($manifest->id, $manifest->organization_id));

            return $manifest;
        });
    }

    /**
     * Scan-driven receipt (spec §5.4 step 4): call once per scan or per batch.
     * Scanning the same barcode again with the same finding changes nothing;
     * the manifest is received once every sample is accounted for.
     *
     * @param  list<ReceiptScan>  $scans
     */
    public function receive(StaffContext $staff, Manifest $manifest, array $scans, ?bool $temperatureOk, ?string $temperatureC): Manifest
    {
        $this->branchGuard->assertActsFor($manifest->to_branch_id, 'receive this manifest');

        return DB::transaction(function () use ($staff, $manifest, $scans, $temperatureOk, $temperatureC): Manifest {
            $manifest = Manifest::query()->lockForUpdate()->findOrFail($manifest->id);

            if (! in_array($manifest->status, [ManifestStatus::Dispatched, ManifestStatus::PartiallyReceived], true)) {
                throw SampleError::manifestNotInTransit();
            }

            $items = $this->inOrganization($manifest->organization_id, fn () => ManifestItem::query()
                ->with('sample')
                ->where('transfer_id', $manifest->id)
                ->get()
                ->keyBy(fn (ManifestItem $item) => $item->sample->barcode));
            $now = CarbonImmutable::now();

            foreach ($this->newScans($items, $scans) as $scan) {
                $this->scanIn($staff, $manifest, $items[$scan->barcode], $scan, $now);
            }

            $this->recordReceipt($staff, $manifest, $items, $temperatureOk, $temperatureC, $now);

            return $manifest;
        });
    }

    /**
     * Puts a sample in the open bag from where it is to the lab that will test
     * it, opening the bag if needed (spec §9 SampleCollected). Nothing to do
     * when it is already at its lab or already bagged.
     */
    public function bagForItsLab(string $sampleId, string $organizationId): void
    {
        $this->inOrganization($organizationId, function () use ($sampleId, $organizationId): void {
            DB::transaction(function () use ($sampleId, $organizationId): void {
                $sample = Sample::query()->lockForUpdate()->find($sampleId);

                if ($sample === null || $sample->current_branch_id === $sample->processing_branch_id || ! in_array($sample->status, self::SHIPPABLE, true)) {
                    return;
                }

                if ($this->openManifestOf($sample) !== null) {
                    return;
                }

                $manifest = $this->openManifestQuery($sample->current_branch_id, $sample->processing_branch_id)->lockForUpdate()->first()
                    ?? $this->openOrJoin($organizationId, $sample->current_branch_id, $sample->processing_branch_id);
                $this->bag($manifest, new Collection([$sample]));
            });
        });
    }

    /**
     * Samples and their scan state, read at organization level: a hub that
     * forwarded a sample still sees what it sent.
     */
    public function loadItems(Manifest $manifest): Manifest
    {
        return $this->inOrganization($manifest->organization_id, fn () => $manifest->load(['items' => fn ($items) => $items->with('sample')->orderBy('id')]));
    }

    private function open(string $organizationId, string $fromBranchId, string $toBranchId): Manifest
    {
        $format = (string) ($this->network->organizationSettings($organizationId)['manifest_number_format'] ?? self::DEFAULT_NUMBER_FORMAT);

        $manifest = new Manifest([
            'from_branch_id' => $fromBranchId,
            'to_branch_id' => $toBranchId,
            'status' => ManifestStatus::Created,
        ]);
        $manifest->organization_id = $organizationId;
        $manifest->manifest_no = $this->sequences->nextFormatted($organizationId, "manifest:{$fromBranchId}", $format, [
            'branch_code' => $this->network->branchCode($fromBranchId),
        ]);
        $manifest->save();
        $this->auditLogger->recordCreated('manifest.create', $manifest);

        return $manifest;
    }

    /** Another request may open the route's bag at the same moment; then join theirs. */
    private function openOrJoin(string $organizationId, string $fromBranchId, string $toBranchId): Manifest
    {
        try {
            return DB::transaction(fn () => $this->open($organizationId, $fromBranchId, $toBranchId));
        } catch (UniqueConstraintViolationException) {
            return $this->openManifestQuery($fromBranchId, $toBranchId)->lockForUpdate()->firstOrFail();
        }
    }

    /**
     * Adds samples to an open manifest, checking every one first so a bad
     * scan leaves the bag unchanged.
     *
     * @param  Collection<int, Sample>  $samples
     */
    private function bag(Manifest $manifest, Collection $samples): void
    {
        $problems = [];
        $toAdd = [];

        foreach ($samples as $sample) {
            $openManifest = $this->openManifestOf($sample);

            if ($openManifest?->id === $manifest->id) {
                continue;
            }

            $problem = match (true) {
                ! in_array($sample->status, self::SHIPPABLE, true) => ['SAMPLE_NOT_SHIPPABLE', "The sample is {$sample->status->value} and cannot be sent."],
                $sample->current_branch_id !== $manifest->from_branch_id => ['SAMPLE_NOT_AT_BRANCH', 'The sample is not at the sending branch.'],
                $sample->processing_branch_id !== $manifest->to_branch_id => ['SAMPLE_NOT_FOR_DESTINATION', 'The sample is tested at another lab.'],
                $openManifest !== null => ['SAMPLE_ON_ANOTHER_MANIFEST', "The sample is already on manifest {$openManifest->manifest_no}."],
                default => null,
            };

            if ($problem !== null) {
                $problems[] = ['code' => $problem[0], 'message' => $problem[1], 'barcode' => $sample->barcode];

                continue;
            }

            $toAdd[] = $sample;
        }

        if ($problems !== []) {
            throw SampleError::samplesNotShippable($problems);
        }

        foreach ($toAdd as $sample) {
            $item = new ManifestItem(['sample_id' => $sample->id]);
            $item->transfer_id = $manifest->id;
            $item->save();
        }

        if ($toAdd !== []) {
            $this->auditLogger->record('manifest.sample_add', $manifest, [], ['sample_ids' => array_map(fn (Sample $sample) => $sample->id, $toAdd)]);
        }
    }

    /**
     * Scans not seen before. Repeating a scan with the same finding is a no-op;
     * contradicting an earlier scan is a conflict.
     *
     * @param  BaseCollection<string, ManifestItem>  $items
     * @param  list<ReceiptScan>  $scans
     * @return list<ReceiptScan>
     */
    private function newScans(BaseCollection $items, array $scans): array
    {
        $unknown = array_values(array_filter($scans, fn (ReceiptScan $scan) => ! $items->has($scan->barcode)));
        if ($unknown !== []) {
            throw SampleError::notOnManifest(array_map(fn (ReceiptScan $scan) => $scan->barcode, $unknown));
        }

        $newScans = [];
        foreach ($scans as $scan) {
            $condition = $items[$scan->barcode]->condition;

            if ($condition === ManifestItemCondition::Pending) {
                $newScans[] = $scan;
            } elseif ($condition !== $scan->condition) {
                throw SampleError::alreadyReceived($scan->barcode);
            }
        }

        return $newScans;
    }

    private function scanIn(StaffContext $staff, Manifest $manifest, ManifestItem $item, ReceiptScan $scan, CarbonImmutable $now): void
    {
        $item->update([
            'condition' => $scan->condition,
            'rejection_reason' => $scan->rejectionReason,
            'scanned_at' => $now,
        ]);

        $this->sampleStates->transition($item->sample, SampleStatus::Received, [
            'received_at' => $now,
            'received_by' => $staff->user()->id,
            'current_branch_id' => $manifest->to_branch_id,
        ]);

        if ($scan->condition === ManifestItemCondition::Rejected) {
            $this->rejections->reject($item->sample, (string) $scan->rejectionReason, $scan->rejectionNote);
        }
    }

    /** @param  BaseCollection<string, ManifestItem>  $items */
    private function recordReceipt(StaffContext $staff, Manifest $manifest, BaseCollection $items, ?bool $temperatureOk, ?string $temperatureC, CarbonImmutable $now): void
    {
        $scanned = $items->filter(fn (ManifestItem $item) => $item->condition !== ManifestItemCondition::Pending)->count();
        $status = ManifestReceipt::statusAfterScans($scanned, $items->count());
        $receipt = array_filter([
            'received_by' => $manifest->received_by ?? $staff->user()->id,
            'received_at' => $manifest->received_at ?? $now,
            'temperature_ok' => $temperatureOk,
            'receipt_temp_c' => $temperatureC,
        ], fn ($value) => $value !== null);

        if ($status === $manifest->status) {
            $manifest->update($receipt);

            if ($manifest->wasChanged()) {
                $this->auditLogger->recordChanges('manifest.receipt_update', $manifest);
            }

            return;
        }

        $this->manifestStates->transition($manifest, $status, $receipt);
        event(new ManifestReceived($manifest->id, $manifest->organization_id));
    }

    private function lockOpen(Manifest $manifest): void
    {
        $locked = Manifest::query()->lockForUpdate()->findOrFail($manifest->id);

        if ($locked->status !== ManifestStatus::Created) {
            throw SampleError::manifestNotOpen();
        }
    }

    private function openManifestOf(Sample $sample): ?Manifest
    {
        return $this->inOrganization($sample->organization_id, fn () => Manifest::query()
            ->where('status', ManifestStatus::Created)
            ->whereHas('items', fn ($items) => $items->where('sample_id', $sample->id))
            ->first());
    }

    /** @return Builder<Manifest> */
    private function openManifestQuery(string $fromBranchId, string $toBranchId): Builder
    {
        return Manifest::query()
            ->where('from_branch_id', $fromBranchId)
            ->where('to_branch_id', $toBranchId)
            ->where('status', ManifestStatus::Created);
    }

    /**
     * Samples the caller can see, by barcode; an unknown barcode is a 422.
     *
     * @param  list<string>  $barcodes
     * @return Collection<int, Sample>
     */
    private function samplesByBarcode(array $barcodes): Collection
    {
        $samples = Sample::query()->whereIn('barcode', $barcodes)->get();
        $unknown = array_diff($barcodes, $samples->pluck('barcode')->all());

        if ($unknown !== []) {
            throw ValidationException::withMessages(array_map(
                fn (string $barcode) => "No sample with barcode {$barcode}.",
                array_combine(array_map(fn (int $index) => "barcodes.{$index}", array_keys($unknown)), $unknown),
            ));
        }

        return $samples;
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
