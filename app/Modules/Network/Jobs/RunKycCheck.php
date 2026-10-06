<?php

namespace App\Modules\Network\Jobs;

use App\Modules\Network\Services\FranchiseDocumentService;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Asks the KYC vendor about an uploaded paper. Idempotent: a paper is checked once. */
final class RunKycCheck implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly string $documentId,
        public readonly string $organizationId,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new WithSystemScope($this->organizationId)];
    }

    public function handle(FranchiseDocumentService $documents): void
    {
        $documents->applyVendorCheck($this->documentId);
    }
}
