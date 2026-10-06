<?php

namespace App\Modules\Lab\Jobs;

use App\Modules\Lab\Services\SignatoryService;
use App\Modules\Shared\Jobs\WithSystemScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Signatory auto-disable (spec §9, daily): registrations past `valid_till` stop signing. */
final class DisableExpiredSignatories implements ShouldQueue
{
    use Queueable;

    /** @return list<object> */
    public function middleware(): array
    {
        return [new WithSystemScope(null)];
    }

    public function handle(SignatoryService $signatories): void
    {
        $signatories->disableExpired(CarbonImmutable::today());
    }
}
