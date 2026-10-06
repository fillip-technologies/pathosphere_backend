<?php

namespace App\Modules\Samples\Jobs;

use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Samples\Models\InventoryItem;
use App\Modules\Shared\Jobs\WithSystemScope;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Expiry alerts for stock (spec §9, daily): a branch is told about batches
 * still on its shelf on the day they are exactly 60 and 30 days from expiry.
 */
final class AlertExpiringStock implements ShouldQueue
{
    use Queueable;

    private const DAYS_BEFORE = [60, 30];

    public function handle(NetworkDirectory $network, NotificationService $notifications): void
    {
        $today = CarbonImmutable::parse(CarbonImmutable::now('Asia/Kolkata')->toDateString());
        $dates = array_map(fn (int $days) => $today->addDays($days)->toDateString(), self::DAYS_BEFORE);

        foreach ($network->organizationIds() as $organizationId) {
            WithSystemScope::run($organizationId, function () use ($dates, $network, $notifications): void {
                InventoryItem::query()->whereIn('expiry_date', $dates)->where('quantity', '>', 0)->get()
                    ->each(function (InventoryItem $item) use ($network, $notifications): void {
                        $branch = $network->branchContact($item->branch_id);
                        $notifications->notify('stock_expiring', new NotificationRecipient('branch', $branch->branchId, $branch->organizationId, $branch->phone, null, false), [
                            'branch_name' => $branch->name,
                            'item_name' => $item->name,
                            'batch_no' => $item->batch_no,
                            'expires_on' => $item->expiry_date?->format('d M Y') ?? '',
                        ]);
                    });
            });
        }
    }
}
