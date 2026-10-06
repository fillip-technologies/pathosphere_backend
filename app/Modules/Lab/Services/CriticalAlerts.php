<?php

namespace App\Modules\Lab\Services;

use App\Modules\Booking\Services\OrderReporting;
use App\Modules\Catalogue\Services\TestDirectory;
use App\Modules\Lab\Models\LabResult;
use App\Modules\Lab\Models\WorklistEntry;
use App\Modules\Network\Services\BranchContact;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\NotificationService;

/**
 * Critical values (spec §5.5 step 2, §9 rule 4): the booking branch, the lab
 * and the referring doctor are alerted at once, ignoring quiet hours, one
 * message per critical result.
 */
final class CriticalAlerts
{
    public function __construct(
        private readonly OrderReporting $orders,
        private readonly TestDirectory $tests,
        private readonly NetworkDirectory $network,
        private readonly NotificationService $notifications,
    ) {}

    /** @param  list<string>  $resultIds */
    public function alert(string $worklistEntryId, array $resultIds): void
    {
        $entry = WorklistEntry::query()->findOrFail($worklistEntryId);
        $order = $this->orders->reportFacts($entry->organization_id, $entry->order_id);
        $definition = $this->tests->resultDefinitions([$entry->test_id])[$entry->test_id];
        $lab = $this->network->branchContact($entry->processing_branch_id);
        $recipients = array_filter([
            $this->branchRecipient($this->network->branchContact($order->branchId)),
            $order->branchId === $lab->branchId ? null : $this->branchRecipient($lab),
            $this->orders->doctorRecipient($entry->organization_id, $entry->order_id),
        ]);

        foreach (LabResult::query()->whereKey($resultIds)->where('is_critical', true)->get() as $result) {
            $variables = [
                'patient_name' => $order->patientName,
                'uhid' => $order->uhid,
                'order_no' => $order->orderNo,
                'parameter' => $definition->parameterById($result->test_parameter_id)->name ?? $definition->name,
                'value' => trim("{$result->value} ".($result->unit ?? '')),
                'lab_name' => $lab->name,
            ];

            foreach ($recipients as $recipient) {
                $this->notifications->notify('critical_value', $recipient, $variables, urgent: true);
            }
        }
    }

    private function branchRecipient(BranchContact $branch): NotificationRecipient
    {
        return new NotificationRecipient('branch', $branch->branchId, $branch->organizationId, $branch->phone, null, false);
    }
}
