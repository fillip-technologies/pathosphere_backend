<?php

namespace App\Modules\Network\Jobs;

use App\Modules\Network\Services\AgreementService;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Jobs\WithSystemScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Agreements past their end date expire (spec §5.1: active → expired, System). */
final class ExpireEndedAgreements implements ShouldQueue
{
    use Queueable;

    public function handle(NetworkDirectory $network, AgreementService $agreements): void
    {
        $today = CarbonImmutable::parse(CarbonImmutable::now('Asia/Kolkata')->toDateString());

        foreach ($network->organizationIds() as $organizationId) {
            WithSystemScope::run($organizationId, fn () => $agreements->expireEnded($today));
        }
    }
}
