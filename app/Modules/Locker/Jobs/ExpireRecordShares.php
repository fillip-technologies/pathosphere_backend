<?php

namespace App\Modules\Locker\Jobs;

use App\Modules\Locker\Services\ShareExpiry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Records consents and shares that have ended as expired (spec §7.11). */
final class ExpireRecordShares implements ShouldQueue
{
    use Queueable;

    public function handle(ShareExpiry $expiry): void
    {
        $expiry->expireEnded(CarbonImmutable::now());
    }
}
