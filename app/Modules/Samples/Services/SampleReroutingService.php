<?php

namespace App\Modules\Samples\Services;

use App\Modules\Booking\Services\OrderFulfilment;
use App\Modules\Catalogue\Services\RoutingService;
use App\Modules\Samples\Enums\ManifestStatus;
use App\Modules\Samples\Enums\SampleStatus;
use App\Modules\Samples\Errors\SampleError;
use App\Modules\Samples\Events\SampleRerouted;
use App\Modules\Samples\Models\ManifestItem;
use App\Modules\Samples\Models\Sample;
use App\Modules\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * A lab that cannot run a sample's tests sends it on (spec §5.4 step 6), e.g.
 * when an analyser breaks after booking. The tests move with the container,
 * and it joins the open manifest to its new lab.
 */
final class SampleReroutingService
{
    private const REROUTABLE = [SampleStatus::Collected, SampleStatus::Received];

    public function __construct(
        private readonly OrderFulfilment $orders,
        private readonly RoutingService $routing,
        private readonly BranchGuard $branchGuard,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  string|null  $targetLabId  null: follow the routing rules of the branch holding the sample
     */
    public function reroute(Sample $sample, ?string $targetLabId, string $reason): Sample
    {
        $this->branchGuard->assertActsFor($sample->current_branch_id, 're-route this sample');

        if (! in_array($sample->status, self::REROUTABLE, true)) {
            throw SampleError::notReroutable();
        }

        $orderItemIds = $sample->orderItemIds();
        $testIds = array_values(array_unique($this->orders->testIdsOfItems($sample->organization_id, $orderItemIds)));
        $targetLabId ??= $this->routedLab($sample->current_branch_id, $testIds);

        if ($targetLabId === $sample->processing_branch_id) {
            throw SampleError::rerouteNotNeeded();
        }

        if (! $this->routing->labCanRunAll($sample->organization_id, $targetLabId, $testIds)) {
            throw SampleError::labCannotRun();
        }

        return DB::transaction(function () use ($sample, $targetLabId, $reason, $orderItemIds): Sample {
            $previousLabId = $sample->processing_branch_id;
            $sample->update(['processing_branch_id' => $targetLabId]);
            $this->auditLogger->record('sample.reroute', $sample, ['processing_branch_id' => $previousLabId], [
                'processing_branch_id' => $targetLabId,
                'reason' => $reason,
            ]);
            $this->orders->rerouteItems($sample->organization_id, $orderItemIds, $targetLabId);

            // Out of a bag still headed for the old lab.
            ManifestItem::query()
                ->where('sample_id', $sample->id)
                ->whereHas('manifest', fn ($manifests) => $manifests->where('status', ManifestStatus::Created)->where('to_branch_id', '!=', $targetLabId))
                ->delete();

            event(new SampleRerouted($sample->id, $sample->organization_id));

            return $sample;
        });
    }

    /**
     * The one lab the branch's routing rules send all these tests to now.
     *
     * @param  list<string>  $testIds
     */
    private function routedLab(string $branchId, array $testIds): string
    {
        $labs = array_unique($this->routing->resolve($branchId, $testIds));

        if (in_array(null, $labs, true)) {
            throw SampleError::noRoute();
        }

        if (count($labs) > 1) {
            throw SampleError::routeAmbiguous();
        }

        return (string) reset($labs);
    }
}
