<?php

namespace App\Modules\Samples\Services;

use App\Modules\Booking\Services\OrderFulfilment;
use App\Modules\Network\Services\BranchContact;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Samples\Models\Manifest;
use App\Modules\Samples\Models\Sample;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\NotificationService;

/**
 * Messages about samples and manifests (spec §9). Branches are reached on
 * their registered phone; patients through their own channel preferences.
 */
final class SampleAlerts
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NetworkDirectory $network,
        private readonly OrderFulfilment $orders,
    ) {}

    /** The collecting branch and the patient learn a redraw is needed (spec §5.4 step 5). */
    public function sampleRejected(Sample $rejected, Sample $redraw): void
    {
        $order = $this->orders->factsForSamples($rejected->organization_id, [$rejected->order_id])[$rejected->order_id];
        $collectingBranch = $this->network->branchContact($rejected->collected_branch_id);
        $reason = $this->reasonLabel((string) $rejected->rejection_reason);

        $this->notifications->notify('sample_rejected', $this->branchRecipient($collectingBranch), [
            'barcode' => $rejected->barcode,
            'order_no' => $order->orderNo,
            'reason' => $reason,
            'lab_name' => $this->network->branchContact($rejected->current_branch_id)->name,
            'redraw_barcode' => $redraw->barcode,
        ]);

        $this->notifications->notify('sample_recollection', $this->orders->patientRecipient($rejected->organization_id, $rejected->order_id), [
            'patient_name' => $order->patientName,
            'order_no' => $order->orderNo,
            'reason' => $reason,
            'branch_name' => $collectingBranch->name,
        ]);
    }

    public function manifestDispatched(Manifest $manifest, int $sampleCount): void
    {
        $this->notifications->notify('manifest_dispatched', $this->branchRecipient($this->network->branchContact($manifest->to_branch_id)), [
            'manifest_no' => $manifest->manifest_no,
            'sample_count' => (string) $sampleCount,
            'from_branch' => $this->network->branchContact($manifest->from_branch_id)->name,
            'courier_name' => $manifest->courier_name ?? 'not recorded',
        ]);
    }

    /** Both ends of the route hear about samples that never arrived. */
    public function samplesMissing(Manifest $manifest, int $missingCount): void
    {
        $this->notifyRoute($manifest, 'samples_missing', ['missing_count' => (string) $missingCount]);
    }

    /** Samples still on the road past their stability limit (spec §9 transit delay monitor). */
    public function samplesDelayed(Manifest $manifest, int $delayedCount): void
    {
        $this->notifyRoute($manifest, 'samples_delayed', ['sample_count' => (string) $delayedCount]);
    }

    /** @param  array<string, string>  $variables */
    private function notifyRoute(Manifest $manifest, string $eventKey, array $variables): void
    {
        $from = $this->network->branchContact($manifest->from_branch_id);
        $to = $this->network->branchContact($manifest->to_branch_id);
        $variables += ['manifest_no' => $manifest->manifest_no, 'from_branch' => $from->name, 'to_branch' => $to->name];

        foreach ([$from, $to] as $branch) {
            $this->notifications->notify($eventKey, $this->branchRecipient($branch), $variables);
        }
    }

    private function branchRecipient(BranchContact $branch): NotificationRecipient
    {
        return new NotificationRecipient('branch', $branch->branchId, $branch->organizationId, $branch->phone, null, false);
    }

    private function reasonLabel(string $reason): string
    {
        return (string) (config('pathology.samples.rejection_reasons')[$reason] ?? str_replace('_', ' ', $reason));
    }
}
