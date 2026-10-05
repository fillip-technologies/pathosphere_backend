<?php

namespace App\Modules\Samples\Services;

use App\Modules\Booking\Services\OrderFulfilment;
use App\Modules\Samples\Enums\ManifestStatus;
use App\Modules\Samples\Enums\SampleStatus;
use App\Modules\Samples\Events\SampleRejected;
use App\Modules\Samples\Models\ManifestItem;
use App\Modules\Samples\Models\Sample;
use App\Modules\Samples\StateMachines\SampleStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * A lab rejects a sample it received (spec §5.4 steps 4–5). Rejection is
 * terminal: the tests get free replacement lines, and a new container for
 * them waits at the collecting branch, linked to the rejected one.
 */
final class SampleRejectionService
{
    public function __construct(
        private readonly OrderFulfilment $orders,
        private readonly SampleRegistrar $registrar,
        private readonly SampleStateMachine $sampleStates,
    ) {}

    /**
     * Call either for a sample at the lab, or inside a manifest receipt.
     *
     * @return Sample the redraw awaiting collection
     */
    public function reject(Sample $sample, string $reason, ?string $note): Sample
    {
        return DB::transaction(function () use ($sample, $reason, $note): Sample {
            $this->sampleStates->transition($sample, SampleStatus::Rejected, [
                'rejection_reason' => $reason,
                'rejection_note' => $note,
            ]);

            // A rejected sample travels no further, even if it was already bagged.
            ManifestItem::query()
                ->where('sample_id', $sample->id)
                ->whereHas('manifest', fn ($manifests) => $manifests->where('status', ManifestStatus::Created))
                ->delete();

            $replacementItemIds = $this->orders->replaceWithRecollection($sample->organization_id, $sample->order_id, $sample->orderItemIds());

            $redraw = $this->registrar->register(
                $sample->organization_id,
                $sample->order_id,
                $sample->collected_branch_id,
                $sample->processing_branch_id,
                $sample->sample_type,
                $sample->container_type,
                $replacementItemIds,
                recollectionOfId: $sample->id,
            );

            event(new SampleRejected($sample->id, $redraw->id, $sample->organization_id));

            return $redraw;
        });
    }
}
