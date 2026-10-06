<?php

namespace App\Modules\Lab\Jobs;

use App\Modules\Lab\Models\Signatory;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Jobs\WithSystemScope;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Expiry alerts for signing authority (spec §9, daily): the lab is told on
 * the day a signatory's registration is exactly 60 and 30 days from lapsing,
 * before the daily auto-disable stops them signing.
 */
final class AlertExpiringSignatories implements ShouldQueue
{
    use Queueable;

    private const DAYS_BEFORE = [60, 30];

    public function handle(NetworkDirectory $network, NotificationService $notifications): void
    {
        $today = CarbonImmutable::parse(CarbonImmutable::now('Asia/Kolkata')->toDateString());
        $dates = array_map(fn (int $days) => $today->addDays($days)->toDateString(), self::DAYS_BEFORE);

        foreach ($network->organizationIds() as $organizationId) {
            WithSystemScope::run($organizationId, function () use ($dates, $network, $notifications): void {
                Signatory::query()->where('is_active', true)->whereIn('valid_till', $dates)->get()
                    ->each(function (Signatory $signatory) use ($network, $notifications): void {
                        $lab = $network->branchContact($signatory->branch_id);
                        $notifications->notify('signatory_expiring', new NotificationRecipient('branch', $lab->branchId, $lab->organizationId, $lab->phone, null, false), [
                            'branch_name' => $lab->name,
                            'registration_no' => $signatory->registration_no,
                            'expires_on' => $signatory->valid_till?->format('d M Y') ?? '',
                        ]);
                    });
            });
        }
    }
}
