<?php

namespace App\Modules\Locker\Jobs;

use App\Modules\Locker\Services\HealthInformationTransfers;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Builds, encrypts and pushes the records of one acknowledged ABDM data request. */
final class TransferHealthInformation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly string $transferId,
        public readonly string $organizationId,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new WithSystemScope($this->organizationId)];
    }

    public function handle(HealthInformationTransfers $transfers): void
    {
        $transfers->transfer($this->transferId);
    }
}
