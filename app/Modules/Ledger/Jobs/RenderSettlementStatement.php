<?php

namespace App\Modules\Ledger\Jobs;

use App\Modules\Ledger\Services\SettlementStatements;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Renders, stores and sends a settlement statement. Safe to retry. */
final class RenderSettlementStatement implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly string $settlementId,
        public readonly string $organizationId,
    ) {
        $this->onQueue('low');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new WithSystemScope($this->organizationId)];
    }

    public function handle(SettlementStatements $statements): void
    {
        $statements->publish($this->settlementId);
    }
}
