<?php

namespace App\Modules\Lab\Jobs;

use App\Modules\Booking\Services\OrderReporting;
use App\Modules\Lab\Enums\ReportStatus;
use App\Modules\Lab\Enums\WorklistStatus;
use App\Modules\Lab\Models\Report;
use App\Modules\Lab\Models\WorklistEntry;
use App\Modules\Network\Services\BranchContact;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Jobs\WithSystemScope;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * TAT breach monitor (spec §9, every 15 minutes): tests at a lab past their
 * due time whose report is not released yet. The lab and the booking branch
 * get one alert per order; each test is alerted once.
 */
final class MonitorTurnaroundTimes implements ShouldQueue
{
    use Queueable;

    /** @return list<object> */
    public function middleware(): array
    {
        return [new WithSystemScope(null)];
    }

    public function handle(OrderReporting $orders, NetworkDirectory $network, NotificationService $notifications): void
    {
        $overdue = WorklistEntry::query()
            ->where('status', '!=', WorklistStatus::Withdrawn)
            ->where('due_at', '<', now())
            ->whereNull('tat_alerted_at')
            ->whereNotExists(fn ($released) => $released->select('id')
                ->from((new Report)->getTable())
                ->whereColumn('reports.order_id', 'worklist_entries.order_id')
                ->whereColumn('reports.processing_branch_id', 'worklist_entries.processing_branch_id')
                ->where('reports.status', ReportStatus::Released))
            ->get();

        foreach ($overdue->groupBy(fn (WorklistEntry $entry) => $entry->order_id.'|'.$entry->processing_branch_id) as $entries) {
            $first = $entries->first();
            $order = $orders->reportFacts($first->organization_id, $first->order_id);
            $lab = $network->branchContact($first->processing_branch_id);
            $variables = ['order_no' => $order->orderNo, 'test_count' => (string) $entries->count(), 'lab_name' => $lab->name];

            WorklistEntry::query()->whereKey($entries->pluck('id'))->update(['tat_alerted_at' => now()]);

            foreach (array_unique([$lab->branchId, $order->branchId]) as $branchId) {
                $notifications->notify('tat_breach', $this->branchRecipient($network->branchContact($branchId)), $variables);
            }
        }
    }

    private function branchRecipient(BranchContact $branch): NotificationRecipient
    {
        return new NotificationRecipient('branch', $branch->branchId, $branch->organizationId, $branch->phone, null, false);
    }
}
